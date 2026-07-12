@php
    $record = $getRecord();
    $report = $record->ai_summary ?? '';
    $evidence = $record->recommendation_text ?? '';
    $conclusion = $record->confidence_reasoning ?? '';

    // ── Helper: Render Markdown Tables ──
    $renderParsedTable = function (array $rows): string {
        if (empty($rows)) return '';

        $parsedRows = [];
        foreach ($rows as $row) {
            $trimmed = trim($row, '|');
            $cols = array_map('trim', explode('|', $trimmed));
            $parsedRows[] = $cols;
        }

        $hasHeader = false;
        if (count($parsedRows) > 1) {
            $secondRow = $parsedRows[1];
            $isSeparator = true;
            foreach ($secondRow as $col) {
                if (!preg_match('/^:?-+:?$/', $col) && !empty($col)) {
                    $isSeparator = false;
                    break;
                }
            }
            if ($isSeparator) {
                $hasHeader = true;
            }
        }

        $html = '<div style="overflow-x: auto; margin: 16px 0; border-radius: 8px; border: 1px solid #e2e8f0; box-shadow: 0 1px 2px rgba(0,0,0,0.04);">';
        $html .= '<table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.88em; background-color: white;">';

        $rowIdx = 0;
        foreach ($parsedRows as $cols) {
            if ($hasHeader && $rowIdx === 1) {
                $rowIdx++;
                continue;
            }

            if ($hasHeader && $rowIdx === 0) {
                $html .= '<thead><tr style="background-color: #f8fafc; border-bottom: 2px solid #e2e8f0;">';
                foreach ($cols as $col) {
                    $html .= '<th style="padding: 10px 14px; font-weight: 600; color: #334155; font-size: 0.92em;">' . $col . '</th>';
                }
                $html .= '</tr></thead><tbody>';
            } else {
                $bg = ($rowIdx % 2 === 0) ? '#ffffff' : '#f8fafc';
                $html .= '<tr style="background-color: ' . $bg . '; border-bottom: 1px solid #f1f5f9;">';
                foreach ($cols as $col) {
                    $html .= '<td style="padding: 10px 14px; color: #475569; line-height: 1.55;">' . $col . '</td>';
                }
                $html .= '</tr>';
            }
            $rowIdx++;
        }

        if ($hasHeader) {
            $html .= '</tbody>';
        }
        $html .= '</table></div>';

        return $html;
    };

    // ── Helper: convert markdown bold, tables, lists and blockquotes to rich HTML ──
    $formatText = function (string $text) use ($renderParsedTable): string {
        $html = e($text);

        // Restore any harmless escaped entities we might need or standard text formats
        $html = str_replace(['&amp;strong&amp;', '&#039;'], ['', "'"], $html);

        // 1. Highlight Metrics dynamically in bold markers **text**
        $html = preg_replace_callback('/\*\*(.+?)\*\*/', function($matches) {
            $content = $matches[1];
            $lower = strtolower($content);
            $hasMetric = preg_match('/\d+/', $content);
            $isShort = strlen($content) < 45 && count(explode(' ', $content)) < 6;

            if ($hasMetric && $isShort) {
                // Negative metric change (red badge)
                if (preg_match('/(decrease|drop|decline|fell|plummeted|down|lost|loss|negative|reduction|^-|-\d)/i', $lower)) {
                    return '<span style="display: inline-block; background-color: #fef2f2; color: #b91c1c; padding: 2px 6px; border-radius: 6px; font-weight: 600; border: 1px solid #fee2e2; font-size: 0.9em; white-space: nowrap;">' . $content . '</span>';
                }
                // Positive metric change / spikes (green badge)
                if (preg_match('/(increase|grew|gained|rose|spike|jump|up|positive|^\+|\+\d)/i', $lower)) {
                    return '<span style="display: inline-block; background-color: #f0fdf4; color: #15803d; padding: 2px 6px; border-radius: 6px; font-weight: 600; border: 1px solid #dcfce7; font-size: 0.9em; white-space: nowrap;">' . $content . '</span>';
                }
                // General metrics / Neutral count (blue badge)
                return '<span style="display: inline-block; background-color: #f0f9ff; color: #0369a1; padding: 2px 6px; border-radius: 6px; font-weight: 600; border: 1px solid #e0f2fe; font-size: 0.9em; white-space: nowrap;">' . $content . '</span>';
            }

            // Normal bold
            return '<strong style="color: #0f172a; font-weight: 600;">' . $content . '</strong>';
        }, $html);

        // 2. Parse Markdown Tables line by line
        $lines = explode("\n", $html);
        $inTable = false;
        $tableRows = [];
        $formattedLines = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '|') && str_ends_with($trimmed, '|')) {
                $inTable = true;
                $tableRows[] = $trimmed;
            } else {
                if ($inTable) {
                    $formattedLines[] = $renderParsedTable($tableRows);
                    $tableRows = [];
                    $inTable = false;
                }
                $formattedLines[] = $line;
            }
        }
        if ($inTable) {
            $formattedLines[] = $renderParsedTable($tableRows);
        }

        $html = implode("\n", $formattedLines);

        // 3. Parse blockquotes (lines starting with > or &gt;)
        $html = preg_replace_callback('/^(?:&gt;|>)\s*(.+)$/m', function($matches) {
            return '<div style="border-left: 4px solid #6366f1; background-color: #f8fafc; padding: 12px 16px; margin: 12px 0; border-radius: 0 8px 8px 0; color: #475569; font-style: italic;">' . $matches[1] . '</div>';
        }, $html);

        // 4. Parse bullet points (- or *)
        $html = preg_replace('/^-\s+(.+)/m', '<div style="padding: 6px 0 6px 20px; position: relative; line-height: 1.65;"><span style="position: absolute; left: 4px; color: #6366f1; font-weight: bold; font-size: 1.1em;">›</span>$1</div>', $html);

        // 5. Convert breaks safely and break big text areas into smaller paragraphs
        $lines = explode("\n", $html);
        foreach ($lines as &$line) {
            $trimmed = trim($line);
            if (empty($trimmed)) {
                continue;
            }

            $isStructure = preg_match('/<\/?(table|tr|td|th|thead|tbody|div|blockquote|ul|li|ol|p|h1|h2|h3|h4|h5|h6|pre|code)/i', $line);

            if (!$isStructure) {
                $sentences = preg_split('/(?<=[.?!])\s+(?=[A-Z])/', $line);
                if (count($sentences) > 2) {
                    $chunks = array_chunk($sentences, 2);
                    $paragraphs = [];
                    foreach ($chunks as $chunk) {
                        $paragraphs[] = implode(' ', $chunk);
                    }
                    $line = implode('<br><br>', $paragraphs);
                } else {
                    $line = nl2br($line);
                }
            }
        }
        $html = implode("\n", $lines);

        return $html;
    };

    // ── Section header detection ──
    // Only treat **bold** as a section header if it's at the start of a line
    // and matches known section names. Everything else is inline bold.
    $knownHeaders = [
        'What Happened', 'Cross-Platform Evidence', 'Cross-Platform',
        'Behavioral Analysis', 'Behavioral', 'Root Cause Assessment',
        'Root Cause', 'Email Marketing', 'Performance', 'Deployment',
        'Key Findings', 'Summary', 'Impact Assessment', 'Impact',
        'Traffic Analysis', 'Revenue Analysis', 'Conversion Analysis',
        'Server Logs', 'Error Analysis', 'Log Analysis', 'Application Errors',
    ];
    $headerPattern = '/(?:^|\n)\s*\*\*(' . implode('|', array_map('preg_quote', $knownHeaders)) . ')[^*]*\*\*/i';

    // Split report into sections by known headers at line starts
    $sections = [];
    $parts = preg_split('/(?:^|\n)\s*\*\*([^*]+?)\*\*\s*\n?/m', $report, -1, PREG_SPLIT_DELIM_CAPTURE);

    // Check if the first part captured is a known header or just text
    $introText = '';
    $sectionPairs = [];

    if (!empty($parts)) {
        $introText = trim($parts[0] ?? '');
        for ($i = 1; $i < count($parts); $i += 2) {
            $headerCandidate = trim($parts[$i] ?? '');
            $bodyText = trim($parts[$i + 1] ?? '');

            // Check if this is actually a section header (matches known patterns)
            $isKnownHeader = false;
            foreach ($knownHeaders as $kh) {
                if (stripos($headerCandidate, $kh) !== false) {
                    $isKnownHeader = true;
                    break;
                }
            }

            if ($isKnownHeader) {
                $sectionPairs[] = ['title' => $headerCandidate, 'content' => $bodyText];
            } else {
                // Not a real header — merge back as inline bold text
                $merged = '**' . $headerCandidate . '**' . $bodyText;
                if (!empty($sectionPairs)) {
                    // Append to previous section
                    $lastIdx = count($sectionPairs) - 1;
                    $sectionPairs[$lastIdx]['content'] .= "\n" . $merged;
                } else {
                    $introText .= "\n" . $merged;
                }
            }
        }
    }

    // ── Section styles ──
    $sectionStyles = [
        'What Happened'           => ['icon' => '📊', 'bg' => '#eff6ff', 'border' => '#3b82f6', 'headerBg' => '#dbeafe', 'color' => '#1e40af'],
        'Cross-Platform Evidence' => ['icon' => '🔗', 'bg' => '#f5f3ff', 'border' => '#8b5cf6', 'headerBg' => '#ede9fe', 'color' => '#5b21b6'],
        'Cross-Platform'          => ['icon' => '🔗', 'bg' => '#f5f3ff', 'border' => '#8b5cf6', 'headerBg' => '#ede9fe', 'color' => '#5b21b6'],
        'Behavioral Analysis'     => ['icon' => '🧠', 'bg' => '#fffbeb', 'border' => '#f59e0b', 'headerBg' => '#fef3c7', 'color' => '#92400e'],
        'Behavioral'              => ['icon' => '🧠', 'bg' => '#fffbeb', 'border' => '#f59e0b', 'headerBg' => '#fef3c7', 'color' => '#92400e'],
        'Root Cause'              => ['icon' => '🎯', 'bg' => '#fef2f2', 'border' => '#ef4444', 'headerBg' => '#fee2e2', 'color' => '#991b1b'],
        'Email Marketing'         => ['icon' => '📧', 'bg' => '#ecfdf5', 'border' => '#10b981', 'headerBg' => '#d1fae5', 'color' => '#065f46'],
        'Performance'             => ['icon' => '⚡', 'bg' => '#f0fdfa', 'border' => '#14b8a6', 'headerBg' => '#ccfbf1', 'color' => '#134e4a'],
        'Deployment'              => ['icon' => '🚀', 'bg' => '#fdf4ff', 'border' => '#d946ef', 'headerBg' => '#fae8ff', 'color' => '#86198f'],
        'Key Findings'            => ['icon' => '🔍', 'bg' => '#f0f9ff', 'border' => '#0ea5e9', 'headerBg' => '#e0f2fe', 'color' => '#0c4a6e'],
        'Impact'                  => ['icon' => '💥', 'bg' => '#fff1f2', 'border' => '#f43f5e', 'headerBg' => '#ffe4e6', 'color' => '#9f1239'],
        'Revenue'                 => ['icon' => '💰', 'bg' => '#ecfdf5', 'border' => '#10b981', 'headerBg' => '#d1fae5', 'color' => '#065f46'],
        'Traffic'                 => ['icon' => '📊', 'bg' => '#eff6ff', 'border' => '#3b82f6', 'headerBg' => '#dbeafe', 'color' => '#1e40af'],
        'Conversion'              => ['icon' => '🔄', 'bg' => '#fefce8', 'border' => '#eab308', 'headerBg' => '#fef9c3', 'color' => '#713f12'],
        'Server Logs'             => ['icon' => '📋', 'bg' => '#fff7ed', 'border' => '#f97316', 'headerBg' => '#ffedd5', 'color' => '#9a3412'],
        'Error Analysis'          => ['icon' => '🐛', 'bg' => '#fff7ed', 'border' => '#f97316', 'headerBg' => '#ffedd5', 'color' => '#9a3412'],
        'Log Analysis'            => ['icon' => '📋', 'bg' => '#fff7ed', 'border' => '#f97316', 'headerBg' => '#ffedd5', 'color' => '#9a3412'],
        'Application Errors'      => ['icon' => '🐛', 'bg' => '#fef2f2', 'border' => '#ef4444', 'headerBg' => '#fee2e2', 'color' => '#991b1b'],
    ];
    $defaultStyle = ['icon' => '📋', 'bg' => '#f8fafc', 'border' => '#94a3b8', 'headerBg' => '#f1f5f9', 'color' => '#334155'];

    // ── Parse evidence items ──
    $evidenceItems = preg_split('/(?=\d+\.\s+\*\*)/', $evidence);
    $evidenceItems = array_filter($evidenceItems, fn ($item) => trim($item) !== '');
    if (count($evidenceItems) <= 1) {
        $evidenceItems = preg_split('/(?=\d+\.\s)/', $evidence);
        $evidenceItems = array_filter($evidenceItems, fn ($item) => trim($item) !== '');
    }

    $platformColors = [
        'GA4' => '#4285f4', 'Adobe Commerce' => '#ff6900', 'Adobe' => '#ff6900',
        'Clarity' => '#00bcf2', 'Klaviyo' => '#2bd16f', 'New Relic' => '#008c99',
        'Deployment' => '#d946ef', 'Email' => '#10b981',
    ];
    $fallbackColors = ['#3b82f6', '#22c55e', '#f59e0b', '#8b5cf6', '#ef4444', '#06b6d4'];
@endphp

<div style="display: flex; flex-direction: column; gap: 14px;">
    {{-- ══ Investigation Report ══ --}}
    @if($report)
        {{-- Intro paragraph --}}
        @if($introText)
            <div style="background: linear-gradient(135deg, #f8fafc, #f1f5f9); border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px 22px; line-height: 1.85; color: #334155; font-size: 0.94em;">
                {!! $formatText($introText) !!}
            </div>
        @endif

        {{-- Section cards --}}
        @foreach($sectionPairs as $section)
            @php
                $title = $section['title'];
                $content = $section['content'];
                if (!$content) continue;

                $style = $defaultStyle;
                foreach ($sectionStyles as $key => $s) {
                    if (stripos($title, $key) !== false) { $style = $s; break; }
                }
            @endphp

            <div style="border: 1px solid {{ $style['border'] }}30; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.06);">
                {{-- Section header --}}
                <div style="background: {{ $style['headerBg'] }}; padding: 11px 20px; display: flex; align-items: center; gap: 10px; border-bottom: 2px solid {{ $style['border'] }}40;">
                    <span style="font-size: 1.2em;">{{ $style['icon'] }}</span>
                    <span style="font-weight: 700; color: {{ $style['color'] }}; font-size: 0.95em; letter-spacing: 0.2px;">{{ $title }}</span>
                </div>
                {{-- Section body --}}
                <div style="background: {{ $style['bg'] }}; padding: 16px 20px; line-height: 1.85; color: #374151; font-size: 0.92em;">
                    {!! $formatText($content) !!}
                </div>
            </div>
        @endforeach
    @endif

    {{-- ══ Cross-Platform Data Evidence ══ --}}
    @if($evidence && count($evidenceItems) > 0)
        <div>
            <div style="font-weight: 700; color: #1e293b; font-size: 0.98em; margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 1.1em;">📈</span> Cross-Platform Data Evidence
            </div>
            <div style="display: flex; flex-direction: column; gap: 8px;">
                @php $ei = 0; @endphp
                @foreach($evidenceItems as $item)
                    @php
                        $item = trim($item);
                        $item = preg_replace('/^\d+\.\s*/', '', $item);

                        // Detect platform for color
                        $color = $fallbackColors[$ei % count($fallbackColors)];
                        foreach ($platformColors as $plat => $pColor) {
                            if (stripos($item, $plat) !== false) { $color = $pColor; break; }
                        }

                        // Convert all bold, then extract title
                        $itemHtml = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', e($item));
                        $hasTitle = preg_match('/^<strong>(.+?)<\/strong>:?\s*(.*)$/s', $itemHtml, $m);
                        $ei++;
                    @endphp

                    <div style="background: white; border: 1px solid #e5e7eb; border-left: 4px solid {{ $color }}; padding: 12px 16px; border-radius: 6px; box-shadow: 0 1px 2px rgba(0,0,0,0.04);">
                        @if($hasTitle)
                            <div style="font-weight: 600; color: {{ $color }}; margin-bottom: 4px; font-size: 0.82em; text-transform: uppercase; letter-spacing: 0.5px;">{{ strip_tags($m[1]) }}</div>
                            <div style="color: #374151; line-height: 1.7; font-size: 0.92em;">{!! nl2br(trim($m[2])) !!}</div>
                        @else
                            <div style="color: #374151; line-height: 1.7; font-size: 0.92em;">{!! nl2br($itemHtml) !!}</div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- ══ Conclusion ══ --}}
    @if($conclusion)
        <div>
            <div style="font-weight: 700; color: #1e293b; font-size: 0.98em; margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 1.1em;">🎯</span> Conclusion & Recommended Action
            </div>
            <div style="line-height: 1.85; color: #1e293b; background: linear-gradient(135deg, #fef2f2 0%, #fff7ed 50%, #fffbeb 100%); border: 1px solid #fecaca; border-left: 4px solid #ef4444; padding: 18px 22px; border-radius: 8px; font-size: 0.93em;">
                {!! $formatText($conclusion) !!}
            </div>
        </div>
    @endif
</div>
