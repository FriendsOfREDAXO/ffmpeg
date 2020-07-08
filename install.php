<?php

/** @var rex_addon $this */

if (!$this->hasConfig()) {
    $this->setConfig('command', 'ffmpeg -i INPUT OUTPUT');
}
