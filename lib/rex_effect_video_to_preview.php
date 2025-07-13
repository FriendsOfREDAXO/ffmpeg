<?php

/**
 * REDAXO Media Manager Effect
 * Converts videos to MP4 previews with optimized text display 
 * and correct positioning
 */
class rex_effect_video_to_preview extends rex_effect_abstract
{
    private const COMPRESSION_LEVELS = [
        1 => 'videopreview_compression_minimal',
        2 => 'videopreview_compression_low',
        3 => 'videopreview_compression_standard',
        4 => 'videopreview_compression_high',
        5 => 'videopreview_compression_maximum'
    ];

    private const MAX_DURATION = 10;
    private const VIDEO_TYPES = ['mp4', 'm4v', 'avi', 'mov', 'webm'];
    private const START_OFFSET = 2;     // Seconds after start
    private const END_OFFSET = 10;      // Seconds before end

    public function execute()
    {
        try {
            $inputFile = rex_type::notNull($this->media->getMediaPath());
            
            if (!$this->isVideoFile($inputFile)) {
                return;
            }

            if (!$this->isFfmpegAvailable()) {
                throw new rex_exception(rex_i18n::msg('videopreview_error_ffmpeg'));
            }

            $params = $this->validateAndGetParams();
            
            $duration = $this->getVideoDuration($inputFile);
            if ($duration <= 0) {
                throw new rex_exception(rex_i18n::msg('videopreview_error_duration'));
            }
            
            // Position Debug Logging
            rex_logger::factory()->log('media_manager', sprintf(
                'Video Info: Length=%f, Position=%s, SnippetLength=%f', 
                $duration, 
                $params['position'], 
                $params['snippetLength']
            ));
            
            $startPosition = $this->calculateStartPosition(
                $duration,
                $params['snippetLength'],
                $params['position']
            );
            
            // Position Debug Logging
            rex_logger::factory()->log('media_manager', sprintf(
                'Calculated start position: %f seconds', 
                $startPosition
            ));
            
            $outputFile = rex_path::addonCache('media_manager', 
                'media_manager__video_preview_' . md5($inputFile) . '.mp4');

            $this->convertToMp4(
                $inputFile,
                $outputFile,
                $startPosition,
                $params['snippetLength'],
                $params['width'],
                $params['fps'],
                $params['compression']
            );

            if (!file_exists($outputFile) || filesize($outputFile) === 0) {
                throw new rex_exception(rex_i18n::msg('videopreview_error_output'));
            }

            $this->media->setSourcePath($outputFile);
            $this->media->refreshImageDimensions();
            $this->media->setFormat('mp4');
            $this->media->setHeader('Content-Type', 'video/mp4');
            
            register_shutdown_function(static function() use ($outputFile) {
                rex_file::delete($outputFile);
            });

        } catch (rex_exception $e) {
            rex_logger::factory()->logException($e);
            return;
        }
    }

    private function validateAndGetParams(): array
    {
        // Debug raw values
        rex_logger::factory()->log('media_manager', sprintf(
            'Raw Params Debug: position=%s', 
            $this->params['position'] ?? 'not set'
        ));

        return [
            'width' => max(1, intval($this->params['width'] ?? 400)),
            'fps' => max(1, min(30, intval($this->params['fps'] ?? 12))),
            'snippetLength' => min(floatval($this->params['snippet_length'] ?? 2), self::MAX_DURATION),
            'compression' => max(1, min(5, intval($this->params['compression_level'] ?? 3))),
            'position' => $this->normalizePosition($this->params['position'] ?? 'middle')
        ];
    }

    private function normalizePosition(string $position): string
    {
        switch ($position) {
            case rex_i18n::msg('videopreview_position_start'):
            case 'start':
                return 'start';
            case rex_i18n::msg('videopreview_position_end'):
            case 'end':
                return 'end';
            case rex_i18n::msg('videopreview_position_middle'):
            case 'middle':
            default:
                return 'middle';
        }
    }

    private function calculateStartPosition(float $duration, float $snippetLength, string $position): float
    {
        // Ensure snippet length doesn't exceed video duration
        $snippetLength = min($snippetLength, $duration);
        
        // Position Debug Logging
        rex_logger::factory()->log('media_manager', sprintf(
            'Position Debug: position=%s, duration=%f, snippetLength=%f', 
            $position,
            $duration,
            $snippetLength
        ));

        switch ($position) {
            case 'start':
                if ($duration > (self::START_OFFSET + $snippetLength)) {
                    return self::START_OFFSET;
                }
                return 0.0;

            case 'end':
                if ($duration <= (self::END_OFFSET + $snippetLength)) {
                    return max(0.0, $duration - $snippetLength);
                }
                return max(0.0, $duration - self::END_OFFSET - $snippetLength);

            case 'middle':
            default:
                $middlePoint = $duration / 2;
                return max(0.0, min(
                    $middlePoint - ($snippetLength / 2),
                    $duration - $snippetLength
                ));
        }
    }

    private function convertToMp4($input, $output, $start, $length, $width, $fps, $compression)
    {
        // Optimized filter chain for text display
        $filters = [];
        
        // Unsharp mask for noise reduction
        if ($compression > 3) {
            $filters[] = 'unsharp=3:3:0.3:3:3:0.1';
        }
        
        // Base scaling with high quality
        $filters[] = sprintf('scale=%d:-1:flags=lanczos+accurate_rnd', $width);
        
        // Text optimization through slight sharpening
        $filters[] = 'eq=contrast=1.1';
        
        if ($compression <= 3) {
            // Additional sharpening for text at lower compression
            $filters[] = 'unsharp=5:5:1.0:5:5:0.0';
        }
        
        // FPS and crop
        $filters[] = sprintf('fps=%d', $fps);
        $filters[] = 'crop=trunc(iw/2)*2:trunc(ih/2)*2';

        $cmd = sprintf(
            'ffmpeg -y ' .
            '-ss %f -t %f '.
            '-i %s '.
            '-vf "%s" '.
            '-c:v h264 '.
            '-preset ultrafast '.
            '-crf 23 '.
            '-profile:v baseline '.
            '-pix_fmt yuv420p '.
            '-movflags +faststart '.
            '-an '.
            '-threads 4 '.
            '%s 2>&1',
            $start,
            $length,
            escapeshellarg($input),
            implode(',', $filters),
            escapeshellarg($output)
        );

        $this->executeCommand($cmd);
    }

    private function executeCommand($cmd)
    {
        exec($cmd, $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new rex_exception(sprintf(
                '%s: %s', 
                rex_i18n::msg('videopreview_error_command'),
                $cmd . "\n" . implode("\n", $output)
            ));
        }
    }

    private function getVideoDuration($inputFile): float
    {
        $cmd = sprintf(
            'ffprobe -v error -select_streams v:0 '.
            '-show_entries stream=duration -of default=noprint_wrappers=1:nokey=1 %s',
            escapeshellarg($inputFile)
        );
        
        exec($cmd, $output, $returnCode);
        
        if ($returnCode !== 0 || empty($output)) {
            return 0.0;
        }
        
        return (float) $output[0];
    }

    private function isVideoFile($file): bool
    {
        if (!file_exists($file)) {
            return false;
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        return in_array($ext, self::VIDEO_TYPES);
    }

    private function isFfmpegAvailable(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        
        exec('ffmpeg -version', $output, $returnCode);
        return $returnCode === 0;
    }

    public function getName()
    {
        return rex_i18n::msg('videopreview_to_preview');
    }

    public function getParams()
    {
        $notice = '';
        if (!$this->isFfmpegAvailable()) {
            $notice = '<strong>' . rex_i18n::msg('videopreview_error_ffmpeg') . '</strong><br>';
        }

        return [
            [
                'label' => rex_i18n::msg('videopreview_position_label'),
                'name' => 'position',
                'type' => 'select',
                'options' => [
                    'end' => rex_i18n::msg('videopreview_position_end'),
                    'middle' => rex_i18n::msg('videopreview_position_middle'),
                    'start' => rex_i18n::msg('videopreview_position_start')
                ],
                'default' => 'middle',
                'notice' => rex_i18n::msg('videopreview_position_notice'),
                'prefix' => $notice
            ],
            [
                'label' => rex_i18n::msg('videopreview_width_label'),
                'name' => 'width',
                'type' => 'int',
                'default' => '400',
                'notice' => rex_i18n::msg('videopreview_width_notice')
            ],
            [
                'label' => rex_i18n::msg('videopreview_compression_label'),
                'name' => 'compression_level',
                'type' => 'select',
                'options' => array_map(function($key) {
                    return rex_i18n::msg($key);
                }, self::COMPRESSION_LEVELS),
                'default' => '3',
                'notice' => rex_i18n::msg('videopreview_compression_notice')
            ],
            [
                'label' => rex_i18n::msg('videopreview_fps_label'),
                'name' => 'fps',
                'type' => 'int',
                'default' => '12',
                'notice' => rex_i18n::msg('videopreview_fps_notice')
            ],
            [
                'label' => rex_i18n::msg('videopreview_length_label'),
                'name' => 'snippet_length',
                'type' => 'int',
                'default' => '2',
                'notice' => rex_i18n::msg('videopreview_length_notice', self::MAX_DURATION)
            ]
        ];
    }
}
