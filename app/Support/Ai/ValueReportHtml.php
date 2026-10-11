<?php

namespace App\Support\Ai;

use App\Domain\Ai\Money;
use App\Domain\Ai\ValueDashboard;
use App\Enums\Ai\RequestStatus;

/**
 * HTML of "Descargar para la junta" (`ai.value.export`, rendered to PDF by
 * dompdf): the value dashboard figures as plain tables. Every value is
 * escaped; money appears only when the metrics carry it (the caller already
 * nulls it without `ai.costs.view`).
 */
final class ValueReportHtml
{
    /**
     * @param  array<string, mixed>|null  $metrics
     * @param  list<array<string, mixed>>  $products
     * @param  list<string>  $notes
     */
    public static function render(?array $metrics, array $products, array $notes, string $scope): string
    {
        $e = fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $hours = fn (int|float $value): string => $e(ValueDashboard::hours((float) $value));

        $html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Valor de las apps</title><style>'
            .'body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#1f2937}h1{font-size:20px;margin:0 0 4px}'
            .'h2{font-size:13px;margin:18px 0 6px}p{margin:2px 0}table{width:100%;border-collapse:collapse}'
            .'th,td{border-bottom:1px solid #e5e7eb;padding:4px 6px;text-align:left}th{background:#f3f4f6}.n{text-align:right}'
            .'</style></head><body>';
        $html .= '<h1>Valor de las apps · Gestor IA</h1><p>'.$e($scope).'</p>';

        if ($metrics === null) {
            return $html.'<p>Aún no hay calificaciones ni horas ahorradas reportadas en este periodo.</p></body></html>';
        }

        $html .= '<p>'.$e($metrics['period']['from']).' a '.$e($metrics['period']['to']).'</p>';

        $html .= '<h2>Resumen</h2><table>';
        $html .= '<tr><th>Horas ahorradas al mes</th><td class="n">'.$hours($metrics['hours_saved_per_month']['current']).'</td></tr>';
        $html .= '<tr><th>Satisfacción</th><td class="n">'.$e($metrics['satisfaction']['average']).' de 5 ('.$e($metrics['satisfaction']['ratings']).' calificaciones)</td></tr>';
        $html .= '<tr><th>De solicitud a producción</th><td class="n">'.$e($metrics['lead_time_days']['current']).' días</td></tr>';
        if ($metrics['monthly_cost'] !== null) {
            $html .= '<tr><th>Costo mensual de las apps</th><td class="n">'.$e(Money::cop($metrics['monthly_cost'])).'</td></tr>';
        }
        $html .= '</table>';

        $html .= '<h2>Horas ahorradas por mes</h2><table><tr><th>Mes</th><th class="n">Horas</th></tr>';
        foreach ($metrics['hours_series'] as $point) {
            $html .= '<tr><td>'.$e($point['month']).'</td><td class="n">'.$hours($point['value']).'</td></tr>';
        }
        $html .= '</table>';

        $html .= '<h2>Horas ahorradas por área</h2><table><tr><th>Área</th><th class="n">Horas al mes</th></tr>';
        foreach ($metrics['hours_by_area'] as $row) {
            $html .= '<tr><td>'.$e($row['area']).'</td><td class="n">'.$hours($row['hours']).'</td></tr>';
        }
        $html .= '</table>';
        foreach ($notes as $note) {
            $html .= '<p>'.$e($note).'</p>';
        }

        if ($products !== []) {
            $html .= '<h2>Productos que más ahorran</h2><table><tr><th>Producto</th><th class="n">Horas al mes</th></tr>';
            foreach ($products as $product) {
                $html .= '<tr><td>'.$e($product['name']).'</td><td class="n">'.$hours($product['hours_per_month']).'</td></tr>';
            }
            $html .= '</table>';
        }

        if (is_array($metrics['roi']) && $metrics['roi'] !== []) {
            $html .= '<h2>Retorno</h2><table><tr><th>Producto</th><th class="n">Costó</th><th class="n">Ahorra al mes</th><th class="n">Se paga en</th></tr>';
            foreach ($metrics['roi'] as $row) {
                $html .= '<tr><td>'.$e($row['product']).'</td><td class="n">'.$e(Money::cop($row['total_cost'])).'</td><td class="n">'
                    .$e(Money::cop($row['saving_per_month'])).'</td><td class="n">'.$e(str_replace('.', ',', (string) $row['payback_months'])).' meses</td></tr>';
            }
            $html .= '</table>';
        }

        $html .= '<h2>Solicitudes por estado</h2><table><tr><th>Estado</th><th class="n">Cantidad</th></tr>';
        foreach ($metrics['requests_by_status'] as $row) {
            $label = RequestStatus::tryFrom($row['status'])?->label() ?? $row['status'];
            $html .= '<tr><td>'.$e($label).'</td><td class="n">'.$e($row['count']).'</td></tr>';
        }

        return $html.'</table></body></html>';
    }
}
