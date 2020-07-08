<?php

/** @var rex_addon $this */

if (rex::isBackend() && is_object(rex::getUser())) {
    rex_perm::register('demo_addon[]');
    rex_perm::register('demo_addon[config]');
}

if (rex::isBackend() && rex::getUser()) {

    if (is_null(rex_session('ffmpeg_uid', 'string', null))) {
        rex_set_session('ffmpeg_uid', uniqid());
    }

    if (rex_be_controller::getCurrentPagePart(2) == 'ffmpeg') {
        rex_view::addJsFile($this->getAssetsUrl('js/script.js'));
    }

    if (rex_request::get('ffmpeg_video', 'boolean', false)) {

        if (rex_request::get('start', 'boolean', false)) {

            $log = $this->getDataPath('log' . rex_session('ffmpeg_uid', 'string', '') . '.txt');

            if (!file_exists($log)) {
                mkdir(dirname($log), 0755, true); // $path is a file
            }

            file_put_contents($log, '');

            $input = rex_path::media(rex_request('video', 'string'));
            $output = rex_path::media(pathinfo($input, PATHINFO_FILENAME));
            $command = trim($this->getConfig('command')) . " ";

            preg_match_all('/OUTPUT.(.*) /m', $command, $matches, PREG_SET_ORDER, 0);
            if (count($matches) > 0) {
                $file = (trim($matches[0][0]));
                rex_set_session('ffmpeg_input_video_file', $input);
                rex_set_session('ffmpeg_output_video_file', $output . '.' . pathinfo($file, PATHINFO_EXTENSION));
            }

            $command = str_ireplace(['INPUT', 'OUTPUT'], [$input, $output], $command);

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                pclose(popen("start /B " . $command . " 1> $log 2>&1", "r")); // windows
            } else {
                shell_exec($command . " 1> $log 2>&1 >/dev/null &"); //linux
            }

            exit();
        }

        if (rex_request::get('progress', 'boolean', false)) {

            $log = $this->getDataPath('log' . rex_session('ffmpeg_uid', 'string', '') . '.txt');
            $getContent = file_get_contents($log);

            preg_match("/Duration: (.*?), start:/ms", $getContent, $matches);
            if (!empty($rawDuration = $matches[1])) $ar = array_reverse(explode(":", $rawDuration));
            $duration = floatval($ar[0]);
            if (!empty($ar[1])) $duration += intval($ar[1]) * 60;
            if (!empty($ar[2])) $duration += intval($ar[2]) * 60 * 60;
            preg_match_all("/time=(.*?) bitrate/", $getContent, $matches);
            $rawTime = array_pop($matches);
            if (is_array($rawTime)) {
                $rawTime = array_pop($rawTime);
            }
            $ar = array_reverse(explode(":", $rawTime));
            $time = floatval($ar[0]);
            if (!empty($ar[1])) $time += intval($ar[1]) * 60;
            if (!empty($ar[2])) $time += intval($ar[2]) * 60 * 60;

            //progress prec..
            $progress = round(($time / $duration) * 100);

            if ($progress > 98) {
                $results = 'done';
            } elseif (strpos($getContent, 'Qavg') !== false) {
                $results = 'done';
            } elseif (strpos($getContent, 'kb/s:') !== false) {
                $results = 'done';
            } else {
                $results = $progress;
            }

            exit(json_encode(['progress' => $results, 'log' => $getContent]));
        }

        if (rex_request::get('done', 'boolean', false)) {

            if (!function_exists('rex_mediapool_deleteMedia')) {
                require rex_path::addon('mediapool', 'functions/function_rex_mediapool.php');
            }

            $inputFile = rex_session('ffmpeg_input_video_file', 'string', null);
            $outputFile = rex_session('ffmpeg_output_video_file', 'string', null);

            if (!is_null($inputFile) && !is_null($outputFile)) {
                // 1. delete
                if ($this->getConfig('delete') == 1) {
                    $result = rex_mediapool_deleteMedia(pathinfo($inputFile, PATHINFO_BASENAME));
                    rex_unset_session('ffmpeg_input_video_file');
                    $getContent .= sprintf("Source file %s deletion was successful", $inputFile) . PHP_EOL;
                }
                // 2. add media
                $result = rex_mediapool_syncFile(pathinfo($outputFile, PATHINFO_BASENAME), 0, '');
                rex_unset_session('ffmpeg_output_video_file');
                $getContent .= sprintf("Destination file %s was successful add to rex_mediapool", $outputFile) . PHP_EOL;
            } else {
                if ($this->getConfig('delete') == 1) {
                    $getContent .= sprintf("Source file %s deletion was not possible", $inputFile) . PHP_EOL;
                    $getContent .= sprintf("Destination file %s rex_mediapool register was not successful", $outputFile) . PHP_EOL;
                    $getContent .= 'Please execute a mediapool sync by hand' . PHP_EOL;
                }
            }

            exit(json_encode(['log' => $getContent]));
        }
    }
}
