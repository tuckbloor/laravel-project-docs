<?php

namespace DevDocs\LaravelProjectDocs\Rendering;

use DevDocs\LaravelProjectDocs\Data\ProjectDocumentation;
use DevDocs\LaravelProjectDocs\Support\SyntaxHighlighter;
use Dompdf\Dompdf;
use Generator;

class SourcePdfAppender
{
    private const PAGE_MARGIN_X = 28.0;
    private const PAGE_TOP = 30.0;
    private const PAGE_BOTTOM = 38.0;
    private const EDITOR_BAR_HEIGHT = 20.0;
    private const GUTTER_WIDTH = 31.0;
    private const CODE_LEFT_PADDING = 7.0;
    private const CODE_RIGHT_PADDING = 7.0;
    private const FONT_SIZE = 6.25;
    private const LINE_HEIGHT = 8.35;
    private const QUALITY_BADGE_WIDTH = 34.0;

    public function __construct(private readonly SyntaxHighlighter $highlighter)
    {
    }

    /**
     * Append complete source code directly to Dompdf's canvas.
     *
     * Source files are yielded one at a time and never expanded into a giant
     * HTML layout tree. This is intentionally different from the browser HTML
     * renderer and keeps code-heavy PDFs within a predictable memory envelope.
     */
    public function append(Dompdf $dompdf, ProjectDocumentation $documentation): void
    {
        $canvas = $dompdf->getCanvas();
        $fontMetrics = $dompdf->getFontMetrics();
        $mono = $fontMetrics->getFont('DejaVu Sans Mono', 'normal');
        $sans = $fontMetrics->getFont('DejaVu Sans', 'normal');
        $sansBold = $fontMetrics->getFont('DejaVu Sans', 'bold');

        if (! $mono || ! $sans || ! $sansBold) {
            throw new \RuntimeException('Unable to load the PDF fonts required for the source appendix.');
        }

        $files = $this->sourceFiles($documentation);
        $hasFiles = false;

        foreach ($files as $file) {
            $hasFiles = true;
            $this->appendFile($canvas, $file, $mono, $sans, $sansBold);
            gc_collect_cycles();
        }

        if (! $hasFiles) {
            return;
        }
    }

    /** @return Generator<int, array{path:string,kind:string,source:string,destination_lines:array<int, true>,quality_findings:array<int,array<int,array<string,mixed>>>}> */
    private function sourceFiles(ProjectDocumentation $documentation): Generator
    {
        $sections = $documentation->sections;
        $qualityFindings = $this->qualityFindingsByLine($sections);

        foreach (($sections['php']['items'] ?? []) as $file) {
            if (! is_array($file) || ! array_key_exists('source', $file) || $file['source'] === null) {
                continue;
            }

            yield [
                'path' => (string) ($file['path'] ?? 'unknown.php'),
                'kind' => 'php',
                'source' => (string) $file['source'],
                'destination_lines' => $this->destinationLines($file, array_fill_keys(array_keys((array) ($qualityFindings[$this->normalisePath((string) ($file['path'] ?? ''))] ?? [])), true)),
                'quality_findings' => (array) ($qualityFindings[$this->normalisePath((string) ($file['path'] ?? ''))] ?? []),
            ];
        }

        foreach (($sections['frontend']['items'] ?? []) as $file) {
            if (! is_array($file) || ! array_key_exists('source', $file) || $file['source'] === null) {
                continue;
            }

            yield [
                'path' => (string) ($file['path'] ?? 'frontend'),
                'kind' => (string) ($file['kind'] ?? 'frontend'),
                'source' => (string) $file['source'],
                'destination_lines' => array_replace([1 => true], array_fill_keys(array_keys((array) ($qualityFindings[$this->normalisePath((string) ($file['path'] ?? ''))] ?? [])), true)),
                'quality_findings' => (array) ($qualityFindings[$this->normalisePath((string) ($file['path'] ?? ''))] ?? []),
            ];
        }

        $envFile = (array) ($sections['project']['env_file'] ?? []);
        if (! empty($envFile['included']) && array_key_exists('source', $envFile) && $envFile['source'] !== null) {
            yield [
                'path' => '.env',
                'kind' => 'env',
                'source' => (string) $envFile['source'],
                'destination_lines' => [1 => true],
                'quality_findings' => [],
            ];
        }
    }

    /** @param array<string, mixed> $file @param array<int,true> $extra @return array<int, true> */
    private function destinationLines(array $file, array $extra = []): array
    {
        $lines = [1 => true];

        foreach (($file['classes'] ?? []) as $class) {
            if (! is_array($class)) {
                continue;
            }

            $start = max(1, (int) ($class['start_line'] ?? 1));
            $lines[$start] = true;

            foreach (($class['methods'] ?? []) as $method) {
                if (! is_array($method)) {
                    continue;
                }
                $line = max(1, (int) ($method['start_line'] ?? $method['line'] ?? 1));
                $lines[$line] = true;
            }
        }

        foreach ($extra as $line => $enabled) {
            if ($enabled) { $lines[max(1, (int) $line)] = true; }
        }

        return $lines;
    }

    /** @param array<string,mixed> $sections @return array<string,array<int,array<int,array<string,mixed>>>> */
    private function qualityFindingsByLine(array $sections): array
    {
        $byPath = [];

        foreach ((array) ($sections['intelligence']['quality_report']['findings'] ?? []) as $index => $finding) {
            if (! is_array($finding)) {
                continue;
            }

            $path = $this->normalisePath((string) ($finding['path'] ?? ''));
            $start = (int) ($finding['start_line'] ?? $finding['line'] ?? 0);
            $meta = (array) ($finding['meta'] ?? []);
            $end = (int) ($finding['end_line'] ?? $meta['end_line'] ?? $start);

            if ($path === '' || $start < 1) {
                continue;
            }

            $end = max($start, min($end, $start + 500));
            $finding['_anchor'] = $this->qualityFindingAnchor($finding, (int) $index);
            $finding['_code'] = 'Q'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);

            for ($line = $start; $line <= $end; $line++) {
                $byPath[$path] ??= [];
                $byPath[$path][$line] ??= [];
                $byPath[$path][$line][] = $finding;
            }
        }

        return $byPath;
    }


    /**
     * @param mixed $canvas Dompdf canvas implementation
     * @param array{path:string,kind:string,source:string,destination_lines:array<int, true>,quality_findings:array<int,array<int,array<string,mixed>>>} $file
     */
    private function appendFile($canvas, array $file, string $mono, string $sans, string $sansBold): void
    {
        $path = $file['path'];
        $language = $this->languageFor($path, $file['kind']);
        $source = str_replace("\t", '    ', str_replace(["\r\n", "\r"], "\n", $file['source']));
        $highlightedLines = $this->highlighter->lines($source, $language);
        $destinationLines = $file['destination_lines'];
        $qualityFindings = (array) ($file['quality_findings'] ?? []);

        $pageWidth = (float) $canvas->get_width();
        $pageHeight = (float) $canvas->get_height();
        $editorX = self::PAGE_MARGIN_X;
        $editorWidth = $pageWidth - (self::PAGE_MARGIN_X * 2);
        $codeX = $editorX + self::GUTTER_WIDTH + self::CODE_LEFT_PADDING;
        $codeWidth = $editorWidth - self::GUTTER_WIDTH - self::CODE_LEFT_PADDING - self::CODE_RIGHT_PADDING - self::QUALITY_BADGE_WIDTH;
        $charWidth = max(2.0, (float) $canvas->get_text_width('M', $mono, self::FONT_SIZE));
        $maxColumns = max(40, (int) floor($codeWidth / $charWidth));

        $y = $this->startSourcePage($canvas, $path, $language, $sans, $sansBold, false);
        $firstLogicalLineOnPage = true;
        $pathHash = substr(sha1(str_replace('\\', '/', $path)), 0, 12);

        foreach ($highlightedLines as $index => $highlightedLine) {
            $lineNumber = $index + 1;
            $lineFindings = (array) ($qualityFindings[$lineNumber] ?? []);
            $severity = $this->highestSeverity($lineFindings);
            $visualRows = $this->wrapHighlightedLine($highlightedLine, $maxColumns);
            if ($visualRows === []) {
                $visualRows = [[]];
            }

            foreach ($visualRows as $rowIndex => $segments) {
                if ($y + self::LINE_HEIGHT > $pageHeight - self::PAGE_BOTTOM) {
                    $y = $this->startSourcePage($canvas, $path, $language, $sans, $sansBold, true);
                    $firstLogicalLineOnPage = true;
                }

                if ($lineFindings !== []) {
                    $canvas->filled_rectangle(
                        $editorX + 0.8,
                        $y - 1.1,
                        $editorWidth - 1.6,
                        self::LINE_HEIGHT + 0.6,
                        $this->qualityBackgroundColor($severity)
                    );
                    $canvas->filled_rectangle(
                        $editorX + 0.8,
                        $y - 1.1,
                        2.2,
                        self::LINE_HEIGHT + 0.6,
                        $this->qualityAccentColor($severity)
                    );
                }

                if ($rowIndex === 0 && isset($destinationLines[$lineNumber])) {
                    $canvas->add_named_dest('src-'.$pathHash.'-L'.$lineNumber);
                }

                if ($lineNumber === 1 && $rowIndex === 0) {
                    $canvas->add_named_dest('source-file-'.$pathHash);
                }

                $number = $rowIndex === 0 ? (string) $lineNumber : '';
                if ($number !== '') {
                    $numberWidth = (float) $canvas->get_text_width($number, $mono, self::FONT_SIZE);
                    $canvas->text(
                        $editorX + self::GUTTER_WIDTH - 5 - $numberWidth,
                        $y,
                        $number,
                        $mono,
                        self::FONT_SIZE,
                        $lineFindings !== [] ? $this->qualityNumberColor($severity) : $this->color('line-number')
                    );
                }

                $x = $codeX;
                foreach ($segments as $segment) {
                    $text = $segment['text'];
                    if ($text === '') {
                        continue;
                    }

                    $canvas->text(
                        $x,
                        $y,
                        $text,
                        $mono,
                        self::FONT_SIZE,
                        $this->color($segment['class'])
                    );
                    $x += (float) $canvas->get_text_width($text, $mono, self::FONT_SIZE);
                }

                if ($rowIndex === 0 && $lineFindings !== []) {
                    $firstFinding = $this->highestSeverityFinding($lineFindings);
                    $count = count($lineFindings);
                    $badge = strtoupper((string) ($firstFinding['severity'] ?? $severity)).($count > 1 ? ' +'.($count - 1) : '');
                    $badgeFontSize = 4.8;
                    $badgeWidth = (float) $canvas->get_text_width($badge, $sansBold, $badgeFontSize);
                    $badgeX = $editorX + $editorWidth - self::CODE_RIGHT_PADDING - $badgeWidth - 3.0;
                    $canvas->text($badgeX, $y + 0.4, $badge, $sansBold, $badgeFontSize, $this->qualityBadgeTextColor($severity));
                    $anchor = (string) ($firstFinding['_anchor'] ?? 'quality');
                    if ($anchor !== '') {
                        $canvas->add_link('#'.$anchor, $badgeX - 2.0, $y - 1.0, $badgeWidth + 4.0, self::LINE_HEIGHT);
                    }
                }

                $y += self::LINE_HEIGHT;
                $firstLogicalLineOnPage = false;
            }
        }
    }

    /**
     * Start a new source appendix page and return the first baseline Y position.
     *
     * @param mixed $canvas
     */
    private function startSourcePage($canvas, string $path, string $language, string $sans, string $sansBold, bool $continuation): float
    {
        $canvas->new_page();

        $pageWidth = (float) $canvas->get_width();
        $pageHeight = (float) $canvas->get_height();
        $x = self::PAGE_MARGIN_X;
        $width = $pageWidth - (self::PAGE_MARGIN_X * 2);
        $editorTop = self::PAGE_TOP + 18;
        $editorBottom = $pageHeight - self::PAGE_BOTTOM - 5;
        $editorHeight = $editorBottom - $editorTop;

        $canvas->text($x, self::PAGE_TOP, 'SOURCE CODE APPENDIX', $sansBold, 7.0, [0.15, 0.32, 0.75]);
        $canvas->filled_rectangle($x, $editorTop, $width, $editorHeight, [0.118, 0.122, 0.133]);
        $canvas->filled_rectangle($x, $editorTop, $width, self::EDITOR_BAR_HEIGHT, [0.169, 0.176, 0.188]);

        $displayPath = $this->truncatePath($path, 94);
        $canvas->text($x + 9, $editorTop + 6.0, $displayPath, $sansBold, 6.5, [0.67, 0.72, 0.78]);
        $languageLabel = strtoupper($this->languageLabel($language));
        $labelWidth = (float) $canvas->get_text_width($languageLabel, $sansBold, 5.8);
        $canvas->text($x + $width - $labelWidth - 9, $editorTop + 6.2, $languageLabel, $sansBold, 5.8, [0.53, 0.56, 0.62]);

        $gutterX = $x + self::GUTTER_WIDTH;
        $canvas->line($gutterX, $editorTop + self::EDITOR_BAR_HEIGHT, $gutterX, $editorBottom, [0.20, 0.21, 0.23], 0.45);

        // Footer/navigation is added to every page by PdfRenderer after the appendix is complete.

        return $editorTop + self::EDITOR_BAR_HEIGHT + 7.0;
    }

    /** @return array<int, array<int, array{text:string,class:string}>> */
    private function wrapHighlightedLine(string $highlightedLine, int $maxColumns): array
    {
        $segments = $this->segments($highlightedLine);
        if ($segments === []) {
            return [[]];
        }

        $rows = [];
        $row = [];
        $columns = 0;

        foreach ($segments as $segment) {
            foreach ($this->characters($segment['text']) as $character) {
                if ($columns >= $maxColumns) {
                    $rows[] = $row;
                    $row = [];
                    $columns = 0;
                }

                $last = array_key_last($row);
                if ($last !== null && $row[$last]['class'] === $segment['class']) {
                    $row[$last]['text'] .= $character;
                } else {
                    $row[] = ['text' => $character, 'class' => $segment['class']];
                }
                $columns++;
            }
        }

        $rows[] = $row;

        return $rows;
    }

    /** @return array<int, array{text:string,class:string}> */
    private function segments(string $highlightedLine): array
    {
        if ($highlightedLine === '') {
            return [];
        }

        $segments = [];
        $pattern = '/<span class="([^"]+)">(.*?)<\/span>|([^<]+)/s';
        preg_match_all($pattern, $highlightedLine, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $class = ! empty($match[1]) ? (string) $match[1] : 'syn-identifier';
            $encoded = ! empty($match[1]) ? (string) ($match[2] ?? '') : (string) ($match[3] ?? '');
            $text = html_entity_decode(strip_tags($encoded), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if ($text !== '') {
                $segments[] = ['text' => $text, 'class' => $class];
            }
        }

        if ($segments === []) {
            $plain = html_entity_decode(strip_tags($highlightedLine), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($plain !== '') {
                $segments[] = ['text' => $plain, 'class' => 'syn-identifier'];
            }
        }

        return $segments;
    }

    /** @return array<int, string> */
    private function characters(string $text): array
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return $chars === false ? str_split($text) : $chars;
    }

    /** @return array{0:float,1:float,2:float} */
    private function color(string $class): array
    {
        return match ($class) {
            'syn-keyword' => [0.89, 0.55, 0.27],
            'syn-type' => [1.00, 0.82, 0.49],
            'syn-function' => [0.46, 0.71, 0.90],
            'syn-variable' => [0.69, 0.54, 0.77],
            'syn-string' => [0.53, 0.66, 0.42],
            'syn-comment' => [0.52, 0.52, 0.52],
            'syn-number' => [0.47, 0.65, 0.81],
            'syn-property', 'syn-operator' => [0.72, 0.75, 0.78],
            'syn-attribute', 'syn-directive' => [0.82, 0.78, 0.29],
            'line-number' => [0.38, 0.39, 0.40],
            default => [0.86, 0.86, 0.86],
        };
    }

    /** @param array<int,array<string,mixed>> $findings @return array<string,mixed> */
    private function highestSeverityFinding(array $findings): array
    {
        $rank = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];
        $best = $findings[0] ?? [];
        foreach ($findings as $finding) {
            $severity = strtolower((string) ($finding['severity'] ?? 'low'));
            $bestSeverity = strtolower((string) ($best['severity'] ?? 'low'));
            if (($rank[$severity] ?? 1) > ($rank[$bestSeverity] ?? 1)) {
                $best = $finding;
            }
        }
        return $best;
    }

    /** @param array<int,array<string,mixed>> $findings */
    private function highestSeverity(array $findings): string
    {
        $rank = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];
        $highest = 'low';
        foreach ($findings as $finding) {
            $severity = strtolower((string) ($finding['severity'] ?? 'low'));
            if (($rank[$severity] ?? 1) > ($rank[$highest] ?? 1)) {
                $highest = $severity;
            }
        }
        return $highest;
    }

    /** @return array{0:float,1:float,2:float} */
    private function qualityBackgroundColor(string $severity): array
    {
        return match ($severity) {
            'critical' => [0.29, 0.12, 0.14],
            'high' => [0.28, 0.16, 0.10],
            'medium' => [0.27, 0.21, 0.10],
            default => [0.12, 0.19, 0.30],
        };
    }

    /** @return array{0:float,1:float,2:float} */
    private function qualityAccentColor(string $severity): array
    {
        return match ($severity) {
            'critical' => [0.94, 0.27, 0.27],
            'high' => [0.98, 0.45, 0.09],
            'medium' => [0.96, 0.62, 0.05],
            default => [0.23, 0.51, 0.96],
        };
    }

    /** @return array{0:float,1:float,2:float} */
    private function qualityNumberColor(string $severity): array
    {
        return match ($severity) {
            'critical' => [1.00, 0.60, 0.60],
            'high' => [1.00, 0.68, 0.39],
            'medium' => [1.00, 0.78, 0.35],
            default => [0.58, 0.75, 1.00],
        };
    }

    /** @return array{0:float,1:float,2:float} */
    private function qualityBadgeTextColor(string $severity): array
    {
        return match ($severity) {
            'critical' => [1.00, 0.68, 0.68],
            'high' => [1.00, 0.72, 0.45],
            'medium' => [1.00, 0.82, 0.45],
            default => [0.64, 0.80, 1.00],
        };
    }

    private function qualityFindingAnchor(array $finding, int $index): string
    {
        return 'quality-finding-'.substr(sha1(implode('|', [
            (string) $index,
            (string) ($finding['severity'] ?? ''),
            (string) ($finding['category'] ?? ''),
            (string) ($finding['title'] ?? ''),
            $this->normalisePath((string) ($finding['path'] ?? '')),
            (string) ($finding['line'] ?? $finding['start_line'] ?? ''),
            (string) ($finding['class'] ?? ''),
            (string) ($finding['method'] ?? ''),
        ])), 0, 14);
    }

    private function normalisePath(string $path): string
    {
        return ltrim(str_replace('\\', '/', trim($path)), './');
    }

    private function languageFor(string $path, string $kind): string
    {
        $lower = strtolower($path);
        $kind = strtolower($kind);

        return match (true) {
            $lower === '.env' || str_ends_with($lower, '/.env') || $kind === 'env' => 'env',
            str_ends_with($lower, '.blade.php') || $kind === 'blade' => 'blade',
            str_ends_with($lower, '.php') || $kind === 'php' => 'php',
            str_ends_with($lower, '.ts'), str_ends_with($lower, '.tsx'), $kind === 'typescript' => 'typescript',
            str_ends_with($lower, '.vue') || $kind === 'vue' => 'vue',
            default => 'javascript',
        };
    }

    private function languageLabel(string $language): string
    {
        return match ($language) {
            'env' => 'ENV',
            'php' => 'PHP',
            'blade' => 'Blade',
            'typescript' => 'TypeScript',
            'vue' => 'Vue',
            default => 'JavaScript',
        };
    }

    private function truncatePath(string $path, int $maxCharacters): string
    {
        $characters = $this->characters($path);
        if (count($characters) <= $maxCharacters) {
            return $path;
        }

        return '…'.implode('', array_slice($characters, -($maxCharacters - 1)));
    }
}
