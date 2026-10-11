<?php

namespace Tests\Feature\Mail;

use App\Support\Mail\EmailTemplate;
use App\Support\Mail\TemplateToBlade;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Blade views under resources/views/mail/ stay faithful to the approved
 * templates in resources/emails/ (design-owned source of truth). When a
 * template changes, regenerate the view with App\Support\Mail\TemplateToBlade.
 */
class TemplateViewsTest extends TestCase
{
    /**
     * @return array<string, array{0: EmailTemplate, 1: string}>
     */
    public static function views(): array
    {
        $cases = [];

        foreach (EmailTemplate::cases() as $template) {
            $cases[$template->value.'.html'] = [$template, 'html'];
            $cases[$template->value.'.txt'] = [$template, 'txt'];
        }

        return $cases;
    }

    #[DataProvider('views')]
    public function test_the_committed_view_is_the_converted_source(EmailTemplate $template, string $format): void
    {
        $this->assertSame(
            TemplateToBlade::convert($this->source($template, $format)),
            file_get_contents($this->viewPath($template, $format)),
            "resources/views/mail/{$this->dir($format)}/{$template->value}.blade.php drifted from resources/emails/{$template->value}.{$format}",
        );
    }

    #[DataProvider('views')]
    public function test_the_view_renders_the_same_visible_content_as_the_source(EmailTemplate $template, string $format): void
    {
        foreach (['segurex', 'derivados'] as $company) {
            $data = $this->sample($template, $company);
            $rendered = view($this->viewName($template, $format), $data)->render();
            $expected = $this->reference($this->source($template, $format), $data, $format === 'html');

            if ($format === 'txt') {
                $this->assertSame($expected, html_entity_decode($rendered, ENT_QUOTES | ENT_HTML5, 'UTF-8'), "{$template->value}.txt ({$company})");
            } else {
                $this->assertSame($this->normalize($expected), $this->normalize($rendered), "{$template->value}.html ({$company})");
            }

            $this->assertStringNotContainsString('{{', $rendered);
            $this->assertStringNotContainsString('<!-- @', $rendered);
            $this->assertStringNotContainsString('<!-- subject:', $rendered);
        }
    }

    #[DataProvider('views')]
    public function test_blocks_and_loops_follow_the_data(EmailTemplate $template, string $format): void
    {
        $data = $this->sample($template, 'derivados');

        foreach (['reply_by_email' => false, 'roadmap_position' => null] as $flag => $off) {
            if (array_key_exists($flag, $data)) {
                $data[$flag] = $off;
            }
        }

        $rendered = view($this->viewName($template, $format), $data)->render();

        $this->assertSame(
            $this->normalize($this->reference($this->source($template, $format), $data, $format === 'html')),
            $this->normalize($format === 'txt' ? html_entity_decode($rendered, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $rendered),
        );
        $this->assertStringNotContainsString('También puedes responder este correo', $rendered);
        $this->assertStringNotContainsString('Si se aprueba, queda en el', $rendered);
    }

    public function test_the_project_report_renders_without_any_cost_variable_for_the_other_group(): void
    {
        $samples = json_decode((string) file_get_contents(resource_path('emails/samples.json')), true);
        $template = EmailTemplate::ProjectReport;
        $data = array_intersect_key(
            [...$this->sample($template, 'segurex'), ...$samples['templates'][$template->value]['without_costs']['data']],
            array_flip($template->allowedVariables(withCosts: false)),
        );

        $this->assertFalse($data['can_view_costs']);
        $this->assertSame([], array_values(preg_grep('/cost|hour|budget|rate|approver/', array_diff(array_keys($data), ['can_view_costs']))));

        foreach (['html', 'txt'] as $format) {
            $rendered = view($this->viewName($template, $format), $data)->render();
            $visible = $format === 'txt' ? html_entity_decode($rendered, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $rendered;

            $this->assertSame(
                $this->normalize($this->reference($this->source($template, $format), $data, $format === 'html')),
                $this->normalize($visible),
            );
            $this->assertDoesNotMatchRegularExpression('/\$\s?\d|\d+ h de|presupuesto/u', $visible);
            $this->assertStringContainsString('Recibes este informe porque pediste el proyecto P-07 (GIA-139).', $visible);
        }
    }

    public function test_the_requirement_hides_the_secrets_warning_when_no_keys_are_asked(): void
    {
        $data = [...$this->sample(EmailTemplate::Requirement, 'derivados'), 'asks_for_secrets' => false, 'is_overdue' => false];

        foreach (['html', 'txt'] as $format) {
            $rendered = view($this->viewName(EmailTemplate::Requirement, $format), $data)->render();

            $this->assertStringNotContainsString('No envíes las claves por correo', $rendered);
            $this->assertStringContainsString('Vence el jueves 1 oct', html_entity_decode($rendered, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
    }

    public function test_html_views_keep_outlook_conditionals_and_have_no_svg_or_tracking(): void
    {
        foreach (EmailTemplate::cases() as $template) {
            $view = file_get_contents($this->viewPath($template, 'html'));

            $this->assertStringContainsString('<!--[if mso]>', $view);
            $this->assertStringNotContainsString('{!!', $view);
            $this->assertDoesNotMatchRegularExpression('/<svg\b|\.svg\b|<script\b/i', $view);
            $this->assertDoesNotMatchRegularExpression('/<img\b[^>]*\b(width|height)="[01]"/i', $view);
        }
    }

    public function test_subjects_match_the_source_comments(): void
    {
        foreach (EmailTemplate::cases() as $template) {
            preg_match('/<!--\s*subject:\s*(.*?)\s*-->/u', $this->source($template, 'html'), $match);
            $data = $this->sample($template, 'segurex');
            $expected = preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', fn (array $m): string => (string) $data[$m[1]], $match[1]);

            $this->assertSame($expected, $template->subject($data), $template->value);
        }
    }

    /**
     * Shared + company + template sample data (samples.json, like the gallery).
     *
     * @return array<string, mixed>
     */
    private function sample(EmailTemplate $template, string $company): array
    {
        $samples = json_decode((string) file_get_contents(resource_path('emails/samples.json')), true);

        return [...$samples['shared'], ...$samples['companies'][$company], ...$samples['templates'][$template->value]['data']];
    }

    private function source(EmailTemplate $template, string $format): string
    {
        return (string) file_get_contents(resource_path("emails/{$template->value}.{$format}"));
    }

    private function dir(string $format): string
    {
        return $format === 'html' ? 'html' : 'text';
    }

    private function viewName(EmailTemplate $template, string $format): string
    {
        return $format === 'html' ? $template->htmlView() : $template->textView();
    }

    private function viewPath(EmailTemplate $template, string $format): string
    {
        return resource_path("views/mail/{$this->dir($format)}/{$template->value}.blade.php");
    }

    private function normalize(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $html));
    }

    /**
     * Independent reference renderer of the template markers
     * (resources/emails/README.md), without Blade: documentation comments
     * dropped, a marker alone on its line removes the line, blocks and
     * loops evaluated, `{{ }}` escaped in HTML.
     *
     * @param  array<string, mixed>  $data
     */
    private function reference(string $source, array $data, bool $html): string
    {
        $lines = [];

        foreach (preg_split('/\r\n|\n/', $source) ?: [] as $line) {
            $stripped = preg_replace('/<!--\s*(subject|from|to|when|partial):.*?-->/u', '', $line);

            if ($stripped !== $line && trim((string) $stripped) === '') {
                continue;
            }

            // A marker alone on its line (exactly one comment): keep the
            // marker, drop the line break.
            $lines[] = preg_match('/^\s*(<!--\s*@\w+(?:(?!-->).)*-->)\s*$/u', $line, $m) === 1 ? "\x00".$m[1] : $line;
        }

        $text = (string) preg_replace("/\n\x00/", '', implode("\n", $lines));
        $text = ltrim($text, "\x00");
        $tokens = preg_split('/(<!--\s*@\w+.*?-->)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $position = 0;

        return $this->evaluate($tokens, $position, $data, $html);
    }

    /**
     * @param  list<string>  $tokens
     * @param  array<string, mixed>  $scope
     */
    private function evaluate(array $tokens, int &$position, array $scope, bool $html, array $stop = []): string
    {
        $out = '';

        while ($position < count($tokens)) {
            $token = $tokens[$position];

            if (preg_match('/^<!--\s*@(\w+)\s*(.*?)\s*-->$/u', $token, $marker) !== 1) {
                $out .= preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/', function (array $m) use ($scope, $html): string {
                    $value = (string) $this->lookup($scope, $m[1]);

                    return $html ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : $value;
                }, $token);
                $position++;

                continue;
            }

            [, $name, $argument] = $marker;

            if (in_array($name, $stop, true)) {
                return $out;
            }

            $position++;

            if ($name === 'if' || $name === 'optional') {
                $end = $name === 'if' ? 'endif' : 'endoptional';
                $then = $this->evaluate($tokens, $position, $scope, $html, ['else', $end]);
                $else = '';

                if (preg_match('/@else\b/', $tokens[$position] ?? '') === 1) {
                    $position++;
                    $else = $this->evaluate($tokens, $position, $scope, $html, [$end]);
                }

                $position++;
                $out .= $this->lookup($scope, $argument) ? $then : $else;
            } elseif ($name === 'foreach') {
                [$list, $item] = array_map('trim', explode(' as ', $argument));
                $start = $position;

                foreach ((array) $this->lookup($scope, $list) as $value) {
                    $position = $start;
                    $out .= $this->evaluate($tokens, $position, [...$scope, $item => $value], $html, ['endforeach']);
                }

                if ($this->lookup($scope, $list) === []) {
                    $this->evaluate($tokens, $position, $scope, $html, ['endforeach']);
                }

                $position++;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function lookup(array $scope, string $path): mixed
    {
        $value = $scope;

        foreach (explode('.', $path) as $part) {
            $value = is_array($value) ? ($value[$part] ?? null) : null;
        }

        return $value;
    }
}
