<?php

namespace DevDocs\LaravelProjectDocs\Rendering;

use DevDocs\LaravelProjectDocs\Contracts\Renderer;
use DevDocs\LaravelProjectDocs\Data\ProjectDocumentation;
use Dompdf\Dompdf;
use Dompdf\Options;

class PdfRenderer implements Renderer
{
    public function __construct(
        private readonly HtmlRenderer $htmlRenderer,
        private readonly SourcePdfAppender $sourceAppender,
    ) {
    }

    public function format(): string
    {
        return 'pdf';
    }

    public function render(ProjectDocumentation $documentation, string $outputDirectory): string
    {
        $this->ensureDirectory($outputDirectory);
        $qualityOnly = (($documentation->meta['report_mode'] ?? 'full') === 'quality');
        $filename = $qualityOnly ? 'project-quality-report.pdf' : 'project-documentation.pdf';
        $path = rtrim($outputDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename;

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isFontSubsettingEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', $qualityOnly ? 'landscape' : 'portrait');
        // Render the documentation/manual without source code first. Rendering
        // thousands of syntax-highlighted source lines through Dompdf's HTML
        // layout engine is extremely memory intensive. Source is appended in a
        // separate streaming canvas pass below.
        $html = $this->pdfFriendlyHtml($this->htmlRenderer->pdfHtml($documentation, false), $qualityOnly);
        $dompdf->loadHtml($html, 'UTF-8');
        unset($html);
        gc_collect_cycles();
        $dompdf->render();

        // The source appendix is generated file-by-file directly on the PDF
        // canvas. This keeps one final PDF while avoiding a giant DOM tree.
        if (! $qualityOnly) {
            $this->sourceAppender->append($dompdf, $documentation);
            gc_collect_cycles();
        }

        $this->addFooter($dompdf, $documentation);

        file_put_contents($path, $dompdf->output());

        return $path;
    }

    private function pdfFriendlyHtml(string $html, bool $qualityOnly = false): string
    {
        /*
         * Browser documentation keeps source code collapsible. PDF output must
         * always include it, so remove only the details/summary wrapper while
         * preserving the highlighted editor markup, anchors and line chunks.
         */
        $html = preg_replace(
            '#<details class="source-details"[^>]*>\s*<summary>.*?</summary>(.*?)</details>#s',
            '$1',
            $html
        ) ?? $html;

        return str_replace('</style>', $this->pdfCss($qualityOnly).'</style>', $html);
    }

    private function pdfCss(bool $qualityOnly = false): string
    {
        $qualityLayout = $qualityOnly ? <<<'CSS'

/* Quality-only PDF uses the full landscape page for findings and code. */
@page{margin:11mm 8mm 17mm 8mm}
.quality-only-shell,.quality-only-content{display:block!important;width:100%!important;max-width:none!important;margin:0!important;padding:0!important}
.quality-only-content .hero{width:100%!important}
.quality-only-content section{width:100%!important;max-width:none!important}
.quality-only-content .quality-findings,.quality-only-content .quality-finding,.quality-only-content .quality-code-snippet{width:100%!important;max-width:none!important}
.quality-only-content .quality-finding{padding:8px 10px}
.quality-only-content .quality-finding h3{font-size:9.5pt}
.quality-only-content .quality-finding p{font-size:7.4pt;line-height:1.4}
.quality-only-content .quality-code-snippet{overflow:visible!important}
.quality-only-content .quality-code-snippet table{width:100%!important;table-layout:fixed!important;font-size:7.2pt}
.quality-only-content .quality-code-line-number{width:38px!important}
.quality-only-content .quality-code-line{white-space:pre-wrap!important;overflow-wrap:anywhere!important;word-break:normal!important}
.quality-only-content .quality-code-snippet-head{font-size:6.6pt}
CSS
            : '';

        return <<<'CSS'

/* PDF-only layout overrides */
@page{margin:15mm 11mm 18mm 11mm}
html,body{background:#fff;color:#18212f;font-size:9.2pt}
html{scroll-behavior:auto}body{margin:0;padding:0}main{max-width:none;margin:0;padding:0}
a{color:#1d4ed8;text-decoration:none}
.hero{position:static;overflow:visible;background:#111827;color:#fff;border-radius:0;padding:17px 20px;margin:0 0 8px;box-shadow:none;page-break-inside:avoid}.hero-accent{display:none}.hero-content{position:static}.hero h1{font-size:24pt;line-height:1.08;margin:1px 0 6px}.hero-copy{font-size:9pt;line-height:1.4;color:#dbeafe;margin:0}.eyebrow{font-size:7.5pt;color:#93c5fd;margin:0 0 3px}.hero-meta{display:block;margin-top:9px;font-size:7.5pt;color:#bfdbfe}.hero-meta span{display:inline-block;border:0;padding:0 14px 0 0;margin-right:10px}
.doc-nav{position:static;display:block;padding:6px 7px;margin:0 0 12px;border:1px solid #dbe3eb;border-radius:0;background:#f8fafc;box-shadow:none;page-break-inside:avoid}.doc-nav a{display:inline-block;margin:0 10px 0 0;padding:0;color:#1d4ed8;font-size:6.7pt;font-weight:700}.section-intro{font-size:7.5pt;line-height:1.35;margin:-2px 0 7px;color:#64748b}.class-search-hint{display:block;margin:0 0 6px;padding:5px 6px;border:1px solid #dbeafe;border-radius:0;background:#eff6ff;font-size:6.6pt}.class-search-hint strong{margin-right:8px}.class-search-hint span{color:#64748b}
section{background:#fff;border:0;border-radius:0;padding:0;margin:0 0 16px;box-shadow:none;page-break-inside:auto}.section-heading{display:table;width:100%;margin:0 0 9px;padding:0 0 5px;border-bottom:1px solid #cbd5e1;page-break-after:avoid}.section-number{display:table-cell;width:28px;height:auto;border:0;border-radius:0;background:transparent;color:#2563eb;font-size:8pt;font-weight:800;vertical-align:middle}.section-heading>div{display:table-cell;vertical-align:middle}.section-kicker{font-size:6.5pt;line-height:1.1;margin:0 0 1px;color:#2563eb}.section-heading h2{font-size:15pt;line-height:1.15;margin:0;color:#0f172a}
.env-status-banner{display:table;width:100%;margin:0 0 8px;padding:6px 8px;border:1.5px solid;text-decoration:none;page-break-inside:avoid}.env-status-banner>span{display:table-cell;vertical-align:middle}.env-status-banner>span:nth-child(2){padding-left:7px}.env-status-banner>b{display:table-cell;text-align:right;vertical-align:middle;width:52px;font-size:6pt}.env-status-banner strong{display:block;font-size:7pt}.env-status-banner small{display:block;font-size:6pt;line-height:1.3;margin-top:2px}.env-status-icon{width:18px;text-align:center;font-weight:800}.env-status-danger{background:#fff1f2;border-color:#fb7185;color:#9f1239}.env-status-warning{background:#fffbeb;border-color:#fbbf24;color:#92400e}.env-status-safe{background:#f0fdf4;border-color:#86efac;color:#166534}.cards{display:table;width:100%;table-layout:fixed;border-collapse:collapse;margin:0 0 8px}.card{display:table-cell;border:1px solid #d7dee8;border-radius:0;padding:7px 4px;text-align:center;vertical-align:middle;background:#f8fafc}.card-label{display:block;font-size:7.2pt}.card strong{display:block;font-size:15pt;line-height:1.05;margin:3px 0}.card small{font-size:6.2pt;color:#94a3b8}.card-link{color:#18212f;text-decoration:none}.card-jump{display:block;margin-top:3px;color:#2563eb;font-size:5.6pt;font-weight:700}.environment-file-section{margin-top:8px;padding-top:4px;border-top:1px solid #dfe3e8}.env-file-warning,.env-file-notice{display:block;margin:5px 0 7px;padding:6px 7px;border:1px solid;font-size:6.8pt;line-height:1.35;page-break-inside:avoid}.env-file-warning{background:#fff1f2;border-color:#fb7185;color:#9f1239}.env-file-notice.env-file-safe{background:#f0fdf4;border-color:#86efac;color:#166534}.env-file-notice.env-file-missing{background:#fffbeb;border-color:#fbbf24;color:#92400e}.env-file-warning strong,.env-file-notice strong{display:block;margin-bottom:2px}.env-file-warning span,.env-file-notice span{display:block}.env-sensitive-copy{padding:5px 6px;background:#fff1f2;border-left:3px solid #e11d48;color:#9f1239}
.table-wrap{overflow:visible;width:100%;border:0;border-radius:0}table{width:100%;border-collapse:collapse;table-layout:fixed;font-size:7.7pt;margin:0 0 8px}thead{display:table-header-group}tfoot{display:table-footer-group}tr{page-break-inside:avoid}th,td{padding:4px 5px;border-bottom:1px solid #dfe3e8;text-align:left;vertical-align:top;word-wrap:break-word;overflow-wrap:anywhere}th{background:#f1f5f9;color:#475569;font-size:6.5pt}tbody tr:nth-child(even){background:#fbfdff}.class-index-table th:nth-child(1),.class-index-table td:nth-child(1){width:24%}.class-index-table th:nth-child(2),.class-index-table td:nth-child(2){width:10%}.class-index-table th:nth-child(3),.class-index-table td:nth-child(3){width:31%}.class-index-table th:nth-child(4),.class-index-table td:nth-child(4){width:35%}.class-index-name{font-weight:700;color:#0f4c81}.location-link{color:#0369a1;font-family:"DejaVu Sans Mono",monospace;font-size:6.7pt}.routes-table th:nth-child(1),.routes-table td:nth-child(1){width:9%}.routes-table th:nth-child(2),.routes-table td:nth-child(2){width:21%}.routes-table th:nth-child(3),.routes-table td:nth-child(3){width:15%}.routes-table th:nth-child(4),.routes-table td:nth-child(4){width:34%}.routes-table th:nth-child(5),.routes-table td:nth-child(5){width:21%}.relationships-table th:nth-child(1),.relationships-table td:nth-child(1){width:16%}.relationships-table th:nth-child(2),.relationships-table td:nth-child(2){width:28%}.relationships-table th:nth-child(3),.relationships-table td:nth-child(3){width:28%}.relationships-table th:nth-child(4),.relationships-table td:nth-child(4){width:28%}.methods-table th:nth-child(1),.methods-table td:nth-child(1){width:18%}.methods-table th:nth-child(2),.methods-table td:nth-child(2){width:27%}.methods-table th:nth-child(3),.methods-table td:nth-child(3){width:20%}.methods-table th:nth-child(4),.methods-table td:nth-child(4){width:12%}.methods-table th:nth-child(5),.methods-table td:nth-child(5){width:23%}
.inline-code{font-family:"DejaVu Sans Mono",monospace;font-size:6.9pt;border:0;border-radius:0;padding:0;background:transparent;color:#334155}.pill,.kind-badge,.visibility-badge{font-size:6.3pt;border-radius:5px;padding:2px 4px}.file{border-top:1px solid #dfe3e8;padding:10px 0 0;margin:7px 0 0;page-break-inside:auto}.file:first-of-type{border-top:0}.file-heading{display:table;width:100%;margin:0 0 7px;page-break-after:avoid}.file-icon{display:table-cell;width:31px;height:auto;border-radius:0;padding:6px 3px;text-align:center;vertical-align:middle;background:#4f46e5;color:#fff;font-size:6.2pt}.frontend-icon{background:#0f766e}.file-heading>div:nth-child(2){display:table-cell;padding-left:8px;vertical-align:middle}.file-heading h3{font-size:10pt;line-height:1.2;margin:0 0 2px}.back-link{display:table-cell;width:52px;padding-left:5px;text-align:right;vertical-align:middle;background:transparent;font-size:6.2pt}.muted{font-size:7.2pt}.class-block{margin:6px 0 9px;padding:7px 8px;border:1px solid #dfe6ef;border-radius:0;background:#fcfdff;page-break-inside:auto}.class-title{display:block;margin:0 0 4px}.class-title h4{display:inline;font-size:9.5pt;margin-left:5px}.class-location{float:right;color:#0369a1;font-family:"DejaVu Sans Mono",monospace;font-size:6pt}.class-fqcn{margin:2px 0 5px}.class-description{font-size:8pt;line-height:1.4;margin:3px 0 6px}.class-actions{display:block;margin-top:5px;padding-top:4px;border-top:1px solid #e8edf3}.class-actions a{display:inline-block;margin-right:8px;font-size:6.2pt;color:#1d4ed8}.refs,.reference-line{display:block;padding:6px 7px;border-radius:0;font-size:7.2pt;page-break-inside:avoid}.refs strong,.reference-line strong{margin-right:6px}

/* v0.5 intelligence layout + repeated PDF navigation */
.floating-top{display:none}.class-search-hint input{display:none}.pdf-page-nav{display:block;position:fixed;left:0;bottom:-13.2mm;font-family:"DejaVu Sans",sans-serif;font-size:6.2pt;color:#1d4ed8}.pdf-page-nav a{color:#1d4ed8;text-decoration:none}.section-footer{display:block;text-align:right;margin-top:7px;padding-top:4px;border-top:1px solid #eef2f7;page-break-inside:avoid}.section-footer a{display:inline-block;margin-left:8px;font-size:6pt;color:#1d4ed8;text-decoration:none}.category-badge{display:inline-block;padding:2px 4px;border:1px solid #a7f3d0;border-radius:4px;background:#ecfdf5;color:#047857;font-size:6pt;font-weight:700;text-transform:uppercase}.workflow-card,.intel-card{border:1px solid #dfe6ef;border-radius:0;background:#fff;margin:5px 0;padding:6px 7px;page-break-inside:auto}.workflow-title,.intel-card-title{display:block;margin-bottom:5px}.workflow-title strong,.intel-card-title h3{display:inline;font-size:8.5pt;margin:0}.workflow-title code,.intel-card-title .category-badge{float:right}.workflow-steps{display:block}.workflow-step{display:block;padding:4px 5px;margin:2px 0;border:1px solid #e2e8f0;border-radius:0;background:#f8fafc;page-break-inside:avoid}.workflow-type{display:inline-block;width:55px;font-size:5.8pt;color:#2563eb;text-transform:uppercase;font-weight:700}.workflow-arrow{display:block;text-align:center;color:#94a3b8;font-size:6pt;line-height:1}.meta-grid{display:table;width:100%;table-layout:fixed;border-collapse:collapse;margin:4px 0 6px}.meta-item{display:table-cell;border:1px solid #e2e8f0;border-radius:0;padding:4px;background:#f8fafc}.meta-item span{display:block;font-size:5.7pt;color:#64748b;text-transform:uppercase}.meta-item strong{display:block;font-size:7pt;color:#0f172a}.chip-line{font-size:6.7pt;line-height:1.5;margin:4px 0}.info-chip,.warning-chip{display:inline-block;padding:1px 3px;border:1px solid #bfdbfe;border-radius:4px;background:#eff6ff;color:#1d4ed8;font-size:5.8pt;margin:1px}.warning-chip{border-color:#fed7aa;background:#fff7ed;color:#c2410c}.compact-table{font-size:6.8pt}.compact-table th,.compact-table td{padding:3px 4px}.dependency-columns{display:block}.dependency-panel{border:1px solid #dfe3e8;border-radius:0;padding:6px;margin:0 0 6px;page-break-inside:auto}.dependency-panel h3,.subheading{font-size:8.5pt;margin:0 0 4px}.subheading{margin-top:8px}.quality-row{display:table;width:100%;border-bottom:1px solid #edf0f4;padding:4px 0}.quality-row>div{display:table-cell;width:50%;vertical-align:top}.quality-row small{display:block;font-size:6pt;color:#64748b}.success-note{padding:5px 6px;border:1px solid #bbf7d0;background:#f0fdf4;color:#166534;border-radius:0;font-size:6.8pt}.used-by{margin:4px 0 5px;padding:5px 6px;border-left:2px solid #8b5cf6;background:#faf5ff}.used-by>strong{display:block;font-size:5.8pt;color:#6d28d9;text-transform:uppercase}.used-by-items{display:block}.used-by-item{display:block;padding:2px 0;border:0;background:transparent;font-size:6.3pt}.used-by-item em{display:inline-block;width:45px;color:#7c3aed;font-size:5.3pt;font-style:normal;text-transform:uppercase}.used-by-item small{color:#64748b;margin-left:4px}.dependency-line{font-size:6.4pt;margin:4px 0}.dependency-chip{display:block;padding:2px 3px;margin:1px 0;border:1px solid #dbeafe;background:#f8fafc}.frontend-map{margin:0 0 8px;padding:5px 6px;border:1px solid #dbeafe;border-radius:0;background:#f8fbff}.frontend-map-row{display:block;padding:4px 0;border-bottom:1px solid #e7eef8}.frontend-map-row>strong{display:block;margin-bottom:2px}.frontend-map-link{display:block;padding:1px 0;border:0;background:transparent;font-size:6pt}.frontend-map-link em{display:inline-block;width:58px;color:#2563eb;font-size:5.2pt;font-style:normal;text-transform:uppercase}.runtime-table th:nth-child(1),.runtime-table td:nth-child(1){width:12%}.runtime-table th:nth-child(2),.runtime-table td:nth-child(2){width:33%}.runtime-table th:nth-child(3),.runtime-table td:nth-child(3){width:25%}.runtime-table th:nth-child(4),.runtime-table td:nth-child(4){width:30%}

/* v0.9.0: compact release overview and a dedicated clickable contents page */
#contents{page-break-before:always;page-break-after:always}
.release-overview{display:table!important;width:100%;table-layout:fixed;border-spacing:5px;margin:7px 0 6px}.overview-panel{display:table-cell!important;width:50%;padding:7px;border:1px solid #dbe3eb;border-radius:0;background:#fbfdff;vertical-align:top}.overview-panel h3{font-size:7.6pt;margin:0 0 5px}.stack-chips{display:block}.stack-chips span{display:inline-block;margin:1px 2px 1px 0;padding:2px 4px;border:1px solid #bfdbfe;border-radius:7px;background:#eff6ff;color:#1d4ed8;font-size:5.7pt;font-weight:700}.coverage-state{float:right;font-size:4.8pt;padding:1px 3px}.coverage-grid{display:block}.coverage-item{display:inline-block;width:30.5%;margin:1px;padding:3px 4px;border:1px solid #e2e8f0;border-radius:0;vertical-align:top}.coverage-item span{display:block;font-size:4.8pt}.coverage-item strong{display:block;margin:1px 0;font-size:8pt}.coverage-item small{display:block;font-size:4.8pt;line-height:1.15}.coverage-issues{margin-top:3px;padding-top:3px;border-top:1px solid #e2e8f0}.coverage-issues>strong{display:block;font-size:5pt;color:#92400e}.coverage-issues span{display:block;font-size:4.7pt;color:#64748b}.coverage-issues span b{display:inline-block;width:52px;color:#b45309}.coverage-issues small{font-size:4.5pt;color:#94a3b8}.needs-attention{margin:6px 0 0;padding:6px 7px;border:1px solid #fed7aa;border-left:3px solid #f59e0b;border-radius:0;background:#fffaf5;page-break-inside:avoid}.attention-heading{display:block;margin-bottom:4px}.attention-heading>div{display:inline-block;width:70%}.attention-heading>a{float:right;font-size:5.5pt}.attention-heading strong{font-size:7pt}.attention-heading span{font-size:5.4pt}.attention-list{display:block;border:1px solid #e5e7eb;border-radius:0}.attention-row{display:block!important;padding:3px 4px;border-bottom:1px solid #edf2f7;color:#0f172a;text-decoration:none;font-size:5.7pt;page-break-inside:avoid}.attention-row:last-child{border-bottom:0}.attention-row .attention-tier{display:inline-block;width:48px;font-size:4.9pt}.attention-row strong{display:inline-block;width:45%;font-size:5.8pt}.attention-row small{display:inline-block;width:35%;font-size:5.1pt;color:#64748b}.attention-row b{float:right;color:#2563eb}.needs-attention-clear{display:block}.contents-grid{display:block!important}.contents-item{display:table!important;width:100%;table-layout:fixed;margin:0 0 5px;padding:6px 7px;border:1px solid #dbe3eb;border-radius:0;background:#fbfdff;color:#0f172a;text-decoration:none;page-break-inside:avoid}.contents-item>span{display:table-cell!important;width:30px;height:auto;padding:4px;background:#eff6ff;color:#1d4ed8;font-size:6pt;font-weight:700;text-align:center;vertical-align:middle}.contents-item>div{display:table-cell!important;padding-left:7px;vertical-align:middle}.contents-item strong{display:block;font-size:7.2pt}.contents-item small{display:block;margin-top:1px;color:#64748b;font-size:5.7pt}.contents-item>b{display:table-cell!important;width:15px;color:#2563eb;text-align:right;vertical-align:middle}.review-tier-summary{display:block;margin:-2px 0 6px;padding:4px 5px;border:1px solid #e2e8f0;border-radius:0;background:#f8fafc;font-size:5.4pt;page-break-inside:avoid}.review-tier-summary span{display:inline-block;margin-right:4px;padding:2px 3px;border:1px solid #e2e8f0;background:#fff}.review-tier-summary small{display:block;margin-top:3px;color:#64748b}

/* v0.8.8: clearer source-code navigation and quality-card pagination */
#quality{page-break-before:always}
#quality .static-analysis-note{display:block;margin:0 0 6px;padding:6px 7px;border:1px solid #bfdbfe;border-left:3px solid #2563eb;background:#eff6ff;color:#1e3a8a;font-size:6.6pt;line-height:1.3;page-break-inside:avoid!important;break-inside:avoid!important}
#quality .static-analysis-note strong{display:block;font-size:6.4pt;margin-bottom:2px}#quality .static-analysis-note span{display:block;font-size:6.2pt;color:#475569}
#quality .source-quality-legend{display:block;margin:0 0 7px;padding:5px 6px;border:1px solid #dbe3eb;background:#f8fafc;font-size:6pt;page-break-inside:avoid!important;break-inside:avoid!important}#quality .source-quality-legend strong{margin-right:5px}#quality .source-quality-legend span{display:inline-block;margin-right:3px;padding:1px 3px;color:#fff;font-size:5.2pt;font-weight:700}#quality .source-quality-legend small{display:block;margin-top:3px;color:#64748b;font-size:5.6pt}
.quality-summary{display:table!important;width:100%;table-layout:fixed;border-collapse:separate;border-spacing:3px;margin:5px 0 8px;page-break-inside:avoid!important;break-inside:avoid!important}.quality-score,.quality-stat{display:table-cell!important;padding:5px 3px;border:1px solid #dbe3eb;border-radius:0;background:#f8fafc;text-align:center;vertical-align:middle}.quality-score span,.quality-stat span{display:block;font-size:5.4pt;color:#64748b;font-weight:700;text-transform:uppercase}.quality-score strong,.quality-stat strong{display:block;font-size:12pt;line-height:1.05;color:#0f172a}.quality-score small{font-size:5.4pt;color:#94a3b8}.quality-critical{background:#fff1f2}.quality-high{background:#fff7ed}.quality-medium{background:#fffbeb}.quality-low{background:#eff6ff}
.quality-findings{display:block!important;margin:0;padding:0}
.quality-finding{display:block!important;margin:0 0 7px;padding:7px 8px;border:1px solid #d7dee8;border-left:3px solid #94a3b8;border-radius:0;background:#fff;page-break-inside:avoid!important;break-inside:avoid!important;page-break-before:auto;page-break-after:auto}
.quality-finding.severity-critical{border-left-color:#dc2626;background:#fff7f7}.quality-finding.severity-high{border-left-color:#ea580c;background:#fffaf5}.quality-finding.severity-medium{border-left-color:#d97706;background:#fffdf5}.quality-finding.severity-low{border-left-color:#2563eb;background:#f8fbff}
.quality-finding-head{display:block;margin:0 0 3px;page-break-after:avoid}.quality-code,.severity-badge,.confidence-badge,.quality-category{display:inline-block;margin-right:3px;padding:2px 4px;font-size:5.7pt;font-weight:700}.quality-code{background:#0f172a;color:#fff}.quality-finding h3{font-size:9pt;line-height:1.2;margin:4px 0 2px;page-break-after:avoid}.quality-finding p{font-size:7pt;line-height:1.35;margin:0 0 4px;color:#475569}.quality-context{font-size:6.8pt;margin:3px 0;page-break-inside:avoid}.quality-meta{margin-top:4px;page-break-inside:avoid}
.source-link{display:inline-block;padding:2px 4px;border:1px solid #93c5fd;background:#eff6ff;color:#1d4ed8!important;text-decoration:none!important;font-weight:700;line-height:1.25}.source-link-label{display:inline-block;margin-right:4px;padding:1px 3px;background:#2563eb;color:#fff;font-family:"DejaVu Sans",sans-serif;font-size:5.2pt;font-weight:700}.source-link-path{font-family:"DejaVu Sans Mono",monospace}.source-link-arrow{margin-left:4px}.location-link.source-link{font-family:"DejaVu Sans Mono",monospace;font-size:6.2pt}.class-location.source-link{float:right;max-width:60%;font-family:"DejaVu Sans Mono",monospace;font-size:5.8pt}.method-link.source-link{padding:1px 3px}.method-link.source-link .inline-code{font-size:6.4pt;color:#1d4ed8}.source-link-mini{margin-left:3px;font-family:"DejaVu Sans",sans-serif;font-size:4.8pt;text-transform:uppercase;color:#1d4ed8}.quality-location.source-link{display:inline-block;margin-top:4px;padding:3px 5px;font-size:6.2pt}.quality-location.source-link .source-link-label{font-size:5.4pt}.source-action-link{margin-top:2px}

/* Continuous IDE-style source view. No artificial chunk/continued banners. */
.code-editor{background:#1e1f22;color:#d4d4d4;border:1px solid #37393d;border-radius:0;margin:7px 0 10px;font-family:"DejaVu Sans Mono",monospace;page-break-inside:auto}.editor-bar{display:block;min-height:0;padding:6px 8px;background:#2b2d30;border-bottom:1px solid #3a3c40;color:#a9b7c6;font-size:6.5pt;page-break-after:avoid}.window-dots{display:none}.editor-file{display:inline}.editor-language{float:right;margin:0;padding:0;background:transparent;color:#7f848e;font-size:6pt}.continuous-source{padding:4px 0}.pdf-code-chunk{margin:0;padding:0;page-break-inside:avoid;background:#1e1f22}.pdf-code-table{width:100%;border-collapse:collapse;table-layout:auto;margin:0;padding:0;background:#1e1f22;font-family:"DejaVu Sans Mono",monospace;font-size:6.25pt}.pdf-code-table tbody,.pdf-code-table tr,.pdf-code-table tr:nth-child(even){margin:0;padding:0;background:#1e1f22;page-break-inside:avoid}.pdf-code-table td{border:0;background:#1e1f22;padding-top:0;padding-bottom:0;vertical-align:top;line-height:1.27}.pdf-line-number{width:23px;min-width:23px;white-space:nowrap;padding-left:1px;padding-right:4px;text-align:right;color:#606366;border-right:1px solid #33353a}.pdf-line-number code{display:block;margin:0;padding:0;border:0;background:transparent;color:inherit;font-family:"DejaVu Sans Mono",monospace;font-size:6.25pt;line-height:1.27;white-space:nowrap}.pdf-line-source{width:auto;padding-left:5px;padding-right:3px;color:#d4d4d4;white-space:pre-wrap;word-wrap:break-word;overflow-wrap:anywhere}.pdf-line-source code{display:block;margin:0;padding:0;border:0;background:transparent;color:inherit;font-family:"DejaVu Sans Mono",monospace;font-size:6.25pt;line-height:1.27;white-space:pre-wrap}.source-line-anchor{display:inline}
/* Syntax colours are deliberately high contrast so they survive PDF rendering. */
.syn-keyword{color:#e38b45;font-weight:700}.syn-type{color:#ffd27d}.syn-function{color:#76b5e5}.syn-variable{color:#b08ac5}.syn-string{color:#86a96b}.syn-comment{color:#858585;font-style:italic}.syn-number{color:#79a7cf}.syn-property{color:#b7bec8}.syn-attribute,.syn-directive{color:#d0c84b}.syn-operator{color:#b7bec8}.syn-identifier{color:#e1e4e8}
CSS
        .$qualityLayout;
    }

    private function addFooter(Dompdf $dompdf, ProjectDocumentation $documentation): void
    {
        $canvas = $dompdf->getCanvas();
        $projectName = (string) ($documentation->meta['project_name'] ?? 'Laravel Project');
        $qualityOnly = (($documentation->meta['report_mode'] ?? 'full') === 'quality');

        // Draw this after the streamed source appendix has been added so every
        // page - manual and source - receives the same clickable navigation.
        $canvas->page_script(function (int $pageNumber, int $pageCount, $pageCanvas, $fontMetrics) use ($projectName, $qualityOnly): void {
            $font = $fontMetrics->getFont('DejaVu Sans', 'normal');
            $bold = $fontMetrics->getFont('DejaVu Sans', 'bold');
            $width = (float) $pageCanvas->get_width();
            $height = (float) $pageCanvas->get_height();
            $footerY = $height - 25.0;
            $left = 31.0;

            $pageCanvas->line($left, $footerY - 5, $width - $left, $footerY - 5, [0.86, 0.88, 0.91], 0.4);

            $back = 'Back to top';
            $sep = '  ·  ';
            $nav = 'Navigation';
            $backWidth = (float) $pageCanvas->get_text_width($back, $bold, 6.2);
            $sepWidth = (float) $pageCanvas->get_text_width($sep, $font, 6.2);
            $navWidth = (float) $pageCanvas->get_text_width($nav, $bold, 6.2);

            $pageCanvas->text($left, $footerY, $back, $bold, 6.2, [0.11, 0.31, 0.85]);
            $pageCanvas->add_link('#top', $left, $footerY - 1, $backWidth, 8);
            $pageCanvas->text($left + $backWidth, $footerY, $sep, $font, 6.2, [0.50, 0.53, 0.58]);
            $pageCanvas->text($left + $backWidth + $sepWidth, $footerY, $nav, $bold, 6.2, [0.11, 0.31, 0.85]);
            $pageCanvas->add_link('#navigation', $left + $backWidth + $sepWidth, $footerY - 1, $navWidth, 8);

            $title = $projectName.($qualityOnly ? ' - Quality Report' : ' - Developer Documentation');
            $titleWidth = (float) $pageCanvas->get_text_width($title, $font, 6.0);
            $pageCanvas->text(($width - $titleWidth) / 2, $footerY, $title, $font, 6.0, [0.50, 0.53, 0.58]);

            $pageText = 'Page '.$pageNumber.' of '.$pageCount;
            $pageWidth = (float) $pageCanvas->get_text_width($pageText, $font, 7.0);
            $pageCanvas->text($width - $left - $pageWidth, $footerY, $pageText, $font, 7.0, [0.39, 0.43, 0.50]);
        });
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new \RuntimeException("Unable to create output directory [{$directory}].");
        }
    }
}
