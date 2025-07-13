<?php

/**
 * REDAXO Media Manager Effect
 * Converts videos to animated WebP previews with optimized performance
 * and browser-specific handling
 */
class rex_effect_video_to_webp extends rex_effect_abstract
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
    private const CACHE_DURATION = 86400; // 24 hours cache
    
    public function execute()
    {
        try {
            $inputFile = rex_type::notNull($this->media->getMediaPath());
            
            if (!$this->isVideoFile($inputFile)) {
                return;
            }

            // Generate cache key based on input file and parameters
            $cacheKey = md5($inputFile . serialize($this->params));
            $outputFile = rex_path::addonCache('media_manager', 
                'media_manager__video2webp_' . $cacheKey . '.webp');

            // Check if cached version exists and is valid
            if ($this->isValidCache($outputFile)) {
                $this->setMediaFromCache($outputFile);
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
            
            $startPosition = $this->calculateStartPosition(
                $duration,
                $params['snippetLength'],
                $params['position']
            );
            
            // Generate new preview
            $this->generatePreview($inputFile, $outputFile, array_merge($params, [
                'startPosition' => $startPosition
            ]));

            if (!file_exists($outputFile) || filesize($outputFile) === 0) {
                throw new rex_exception(rex_i18n::msg('videopreview_error_output'));
            }

            $this->setMediaFromCache($outputFile);

        } catch (rex_exception $e) {
            rex_logger::factory()->logException($e);
            return;
        }
    }

    private function validateAndGetParams(): array
    {
        return [
            'width' => max(1, intval($this->params['width'] ?? 400)),
            'fps' => max(1, min(30, intval($this->params['fps'] ?? 12))),
            'quality' => $this->getQualityForCompression(intval($this->params['compression_level'] ?? 3)),
            'snippetLength' => min(floatval($this->params['snippet_length'] ?? 2), self::MAX_DURATION),
            'compression' => max(1, min(5, intval($this->params['compression_level'] ?? 3))),
            'position' => $this->normalizePosition($this->params['position'] ?? 'middle')
        ];
    }

    private function getQualityForCompression(int $compression): int 
    {
        $qualityMap = [
            1 => 95, // Minimal
            2 => 85, // Low
            3 => 75, // Standard
            4 => 65, // High
            5 => 55  // Maximum
        ];
        
        $quality = $qualityMap[$compression] ?? 75;
        
        // Reduce quality for Safari to improve performance
        if ($this->isSafari()) {
            $quality = max(1, $quality - 10);
        }
        
        return $quality;
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
        $snippetLength = min($snippetLength, $duration);

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

    private function generatePreview($inputFile, $outputFile, array $params)
    {
        // Optimized filters based on browser and compression level
        $filters = $this->getOptimizedFilters($params);

        $cmd = sprintf(
            'ffmpeg -y ' .
            '-ss %f -t %f '.
            '-i %s '.
            '-vf "%s" '.
            '-vcodec libwebp '.
            '-preset picture '.
            '-compression_level %d '. 
            '-lossless 0 '.
            '-quality %d '.
            '-loop 0 '.
            '-vsync 0 '.
            '-qmin %d '.
            '-qmax %d '.
            '-metadata author="" '.
            '-an -threads 4 '.
            '%s 2>&1',
            $params['startPosition'],
            $params['snippetLength'],
            escapeshellarg($inputFile),
            implode(',', $filters),
            min(4, $params['compression']),
            $params['quality'],
            max(1, $params['compression']),
            min(20, $params['compression'] * 4),
            escapeshellarg($outputFile)
        );

        $this->executeCommand($cmd);
    }

    private function getOptimizedFilters(array $params): array
    {
        $filters = [];
        
        // Base scaling with appropriate quality
        $filters[] = sprintf('scale=%d:-1:flags=lanczos', $params['width']);
        
        // Browser-specific optimizations
        if ($this->isSafari()) {
            // Simplified filter chain for Safari
            $filters[] = 'eq=contrast=1.05';
        } else {
            // More complex filters for other browsers
            if ($params['compression'] > 3) {
                $filters[] = 'unsharp=3:3:0.3:3:3:0.1';
            }
            $filters[] = 'eq=contrast=1.1';
            if ($params['compression'] <= 3) {
                $filters[] = 'unsharp=5:5:1.0:5:5:0.0';
            }
        }
        
        // Common filters
        $filters[] = sprintf('fps=%d', $params['fps']);
        $filters[] = 'crop=trunc(iw/2)*2:trunc(ih/2)*2';
        
        return $filters;
    }

    private function isValidCache($outputFile): bool 
    {
        if (!file_exists($outputFile)) {
            return false;
        }

        $fileAge = time() - filemtime($outputFile);
        return $fileAge < self::CACHE_DURATION;
    }

    private function setMediaFromCache($outputFile)
    {
        $this->media->setSourcePath($outputFile);
        $this->media->refreshImageDimensions();
        $this->media->setFormat('webp');
        $this->media->setHeader('Content-Type', 'image/webp');
        $this->media->setHeader('Cache-Control', 'public, max-age=' . self::CACHE_DURATION);
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

    private function isSafari(): bool
    {
        $userAgent = rex_request::server('HTTP_USER_AGENT', 'string', '');
        return stripos($userAgent, 'Safari') !== false 
            && stripos($userAgent, 'Chrome') === false;
    }

    public function getName()
    {
        return rex_i18n::msg('videopreview_to_webp');
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
