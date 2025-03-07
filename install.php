<?php
// Create data directory for logs if it doesn't exist
if (!rex_dir::isDirectory($this->getDataPath())) {
    rex_dir::create($this->getDataPath());
}
?>
