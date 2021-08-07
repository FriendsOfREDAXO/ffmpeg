<?php

/** @var rex_addon $this */

if (!$this->hasConfig()) {
    $this->setConfig('command', 'ffmpeg -y -i INPUT -vcodec h264   OUTPUT.mp4');
}
