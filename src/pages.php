<?php
// Page counting for printable files. Returns an int, or null when it can't be known.
// Pure PHP (zip + zlib), no external tools needed.

function count_pages($path, $ext) {
    try {
        switch ($ext) {
            case 'pdf':
                return pdf_page_count($path);
            case 'docx':
                return zip_meta_int($path, 'docProps/app.xml', '~<Pages>\s*(\d+)\s*</Pages>~i');
            case 'pptx':
                return zip_meta_int($path, 'docProps/app.xml', '~<Slides>\s*(\d+)\s*</Slides>~i');
            case 'odt':
                return zip_meta_int($path, 'meta.xml', '~page-count="(\d+)"~');
            case 'odp':
                $n = zip_meta_int($path, 'meta.xml', '~page-count="(\d+)"~');
                return $n ?: zip_meta_count($path, 'content.xml', '~<draw:page[\s>]~');
            case 'jpg': case 'jpeg': case 'png': case 'gif': case 'webp': case 'bmp': case 'tif': case 'tiff':
                return 1; // one image = one printed page
            default:
                return null; // xlsx/xls/ods/doc/ppt/rtf/txt/csv: page count depends on print layout
        }
    } catch (Throwable $e) {
        return null;
    }
}

function ooxml_read($path, $name, $limit = 2000000) {
    if (!class_exists('ZipArchive')) {
        return null;
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return null;
    }
    $s = $zip->getStream($name);
    $data = $s ? stream_get_contents($s, $limit) : null;
    if ($s) {
        fclose($s);
    }
    $zip->close();
    return $data ?: null;
}

function zip_meta_int($path, $name, $regex) {
    $x = ooxml_read($path, $name, 200000);
    if ($x !== null && preg_match($regex, $x, $m) && (int)$m[1] > 0) {
        return (int)$m[1];
    }
    return null;
}

function zip_meta_count($path, $name, $regex) {
    $x = ooxml_read($path, $name, 5000000);
    $n = $x !== null ? preg_match_all($regex, $x) : 0;
    return $n > 0 ? $n : null;
}

function pdf_page_count($path) {
    if (filesize($path) > 60 * 1024 * 1024) {
        return null;
    }
    $data = file_get_contents($path);
    if ($data === false || strpos($data, '%PDF') === false) {
        return null;
    }
    $text = $data;
    // PDF 1.5+ may keep the page tree inside compressed object streams: inflate those too
    $budget = 40 * 1024 * 1024;
    $pos = 0;
    while (($s = strpos($data, 'stream', $pos)) !== false && $budget > 0) {
        $pos = $s + 6;
        if ($s >= 3 && substr($data, $s - 3, 3) === 'end') {
            continue;
        }
        $head = substr($data, max(0, $s - 400), min(400, $s));
        if (strpos($head, '/ObjStm') === false) {
            continue;
        }
        $start = $s + 6;
        if (isset($data[$start]) && $data[$start] === "\r") {
            $start++;
        }
        if (isset($data[$start]) && $data[$start] === "\n") {
            $start++;
        }
        $end = strpos($data, 'endstream', $start);
        if ($end === false) {
            break;
        }
        $raw = substr($data, $start, $end - $start);
        $inflated = @gzuncompress($raw);
        if ($inflated === false) {
            $inflated = @gzuncompress(rtrim($raw, "\r\n"));
        }
        if ($inflated !== false) {
            $text .= "\n" . $inflated;
            $budget -= strlen($inflated);
        }
        $pos = $end;
    }
    unset($data);

    // Root /Pages node: its /Count is the total (children have smaller counts)
    $max = 0;
    $off = 0;
    while (preg_match('~/Type\s*/Pages(?![A-Za-z])~', $text, $m, PREG_OFFSET_CAPTURE, $off)) {
        $p = $m[0][1];
        $off = $p + 6;
        $from = max(0, $p - 400);
        $win = substr($text, $from, 800);
        // keep to the same dictionary: cut at the object boundaries around the match
        $rel = $p - $from;
        $a = strrpos(substr($win, 0, $rel), 'obj');
        $win = $a === false ? $win : substr($win, $a);
        $b = strpos($win, 'endobj');
        $win = $b === false ? $win : substr($win, 0, $b);
        if (preg_match_all('~/Count\s+(\d+)~', $win, $c)) {
            $max = max($max, (int)max($c[1]));
        }
    }
    if ($max > 0) {
        return $max;
    }
    $n = preg_match_all('~/Type\s*/Page(?![A-Za-z])~', $text);
    return $n > 0 ? $n : null;
}
