<?php
// Included only by the authenticated panel endpoint after a migration conflict.
if (!isset($networkConflictDetails) || !is_array($networkConflictDetails)) {
    http_response_code(404);
    exit;
}
$conflictEscape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$conflictRows = is_array($networkConflictDetails['conflicts'] ?? null) ? $networkConflictDetails['conflicts'] : [];
$conflictTotal = max(0, (int) ($networkConflictDetails['duplicate_groups'] ?? count($conflictRows)));
$conflictNumber = static function (int $value): string { return number_format($value, 0, ',', '.'); };
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <title>Direcciones duplicadas · ROS Plugin</title>
    <style>
        :root {
            color-scheme: light;
            --primary: #1663cf;
            --primary-hover: #0c52b5;
            --button-primary: #1663cf;
            --link: #1458bb;
            --background: #f3f6fa;
            --surface: #fff;
            --text: #18283d;
            --muted: #536174;
            --border: #dce3ec;
            --subtle: #f7f9fc;
            --warning: #8a5200;
            --warning-bg: #fff5df;
            --warning-border: #eed4a0;
            --blue-bg: #eaf2ff;
            --shadow: 0 8px 28px rgba(24, 40, 61, .05);
        }
        @media (prefers-color-scheme: dark) {
            :root {
                color-scheme: dark;
                --primary: #267dff;
                --primary-hover: #1169ef;
                --button-primary: #1769db;
                --link: #73abff;
                --background: #0f1115;
                --surface: #1c212b;
                --text: #f0f2f5;
                --muted: #a0a9b5;
                --border: #323a48;
                --subtle: #161a22;
                --warning: #ffd18b;
                --warning-bg: #302619;
                --warning-border: #6a4c27;
                --blue-bg: #172d4a;
                --shadow: 0 8px 28px rgba(0, 0, 0, .15);
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--background); color: var(--text); font: 14px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
        a { color: var(--link); }
        a:focus-visible { outline: 3px solid var(--primary); outline-offset: 4px; }
        svg { flex-shrink: 0; }
        .page { max-width: 1040px; margin: 0 auto; padding: 28px 24px 40px; }
        .brand { display: flex; align-items: center; gap: 11px; margin-bottom: 28px; }
        .brand-icon { display: grid; place-items: center; width: 38px; height: 38px; border-radius: 10px; background: var(--blue-bg); color: var(--primary); }
        .brand-name { font-size: 16px; font-weight: 700; letter-spacing: -.3px; }
        .brand-context { color: var(--muted); font-size: 12px; }
        .notice { border: 1px solid var(--border); border-radius: 13px; background: var(--surface); box-shadow: var(--shadow); overflow: hidden; }
        .notice-intro { padding: 25px 28px 24px; border-top: 3px solid var(--warning-border); }
        .status { display: inline-flex; align-items: center; gap: 7px; padding: 5px 10px; border: 1px solid var(--warning-border); border-radius: 999px; background: var(--warning-bg); color: var(--warning); font-size: 12px; font-weight: 650; }
        h1 { margin: 14px 0 9px; font-size: clamp(24px, 4vw, 30px); line-height: 1.2; letter-spacing: -.7px; }
        .intro-copy { max-width: 730px; margin: 0; color: var(--muted); }
        .summary { display: flex; flex-wrap: wrap; align-items: center; gap: 16px 24px; margin-top: 20px; padding-top: 17px; border-top: 1px solid var(--border); }
        .summary-stat { display: flex; align-items: baseline; gap: 8px; }
        .summary-value { font-size: 24px; line-height: 1.2; font-weight: 700; font-variant-numeric: tabular-nums; }
        .summary-label { font-size: 13px; color: var(--muted); }
        .preserved { display: flex; align-items: center; gap: 7px; margin-left: auto; color: var(--muted); font-size: 12px; }
        .section { padding: 22px 28px; border-top: 1px solid var(--border); }
        .section-heading { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: baseline; gap: 4px 16px; margin-bottom: 12px; }
        h2 { margin: 0; font-size: 15px; font-weight: 650; }
        .section-note { margin: 0; font-size: 12px; color: var(--muted); }
        .table-wrap { border: 1px solid var(--border); border-radius: 9px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th { padding: 10px 14px; background: var(--subtle); color: var(--muted); font-size: 11px; font-weight: 650; }
        td { padding: 13px 14px; border-top: 1px solid var(--border); vertical-align: top; font-size: 13px; }
        .address { min-width: 160px; max-width: 320px; font: 13px/1.6 ui-monospace, "Cascadia Code", Consolas, monospace; overflow-wrap: anywhere; }
        .family { display: inline-block; min-width: 42px; padding: 2px 7px; border-radius: 5px; background: var(--blue-bg); color: var(--link); font-size: 11px; font-weight: 650; text-align: center; }
        .service-ids { display: flex; flex-wrap: wrap; gap: 5px; min-width: 130px; }
        .service-id { display: inline-block; padding: 2px 7px; border: 1px solid var(--border); border-radius: 5px; background: var(--subtle); font-size: 12px; font-variant-numeric: tabular-nums; }
        .row-note { display: block; margin-top: 6px; color: var(--muted); font-size: 11px; }
        .count { width: 85px; text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .limit-note { display: flex; align-items: flex-start; gap: 8px; margin: 12px 0 0; color: var(--muted); font-size: 12px; }
        .limit-note svg { margin-top: 2px; }
        .next-step { padding: 15px 17px; border-radius: 9px; background: var(--subtle); }
        .next-step p { margin: 5px 0 0; color: var(--muted); font-size: 13px; }
        .actions { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-top: 17px; }
        .button { display: inline-flex; justify-content: center; align-items: center; gap: 7px; min-height: 38px; padding: 8px 13px; border: 1px solid var(--border); border-radius: 7px; text-decoration: none; color: var(--text); background: var(--surface); font-size: 13px; font-weight: 600; }
        .button:hover { background: var(--subtle); }
        .button-primary { border-color: var(--button-primary); background: var(--button-primary); color: #fff; }
        .button-primary:hover { border-color: var(--primary-hover); background: var(--primary-hover); }
        .footer-note { margin: 16px 0 0; color: var(--muted); font-size: 12px; }
        .empty { padding: 20px; color: var(--muted); text-align: center; }
        @media (max-width: 600px) {
            .page { padding: 20px 12px 28px; }
            .brand { margin-bottom: 20px; }
            .notice-intro, .section { padding: 20px 17px; }
            .preserved { width: 100%; margin-left: 0; }
            th, td { padding: 10px; }
            .address { min-width: 125px; }
            .actions { align-items: stretch; }
            .button { flex: 1 1 200px; }
        }
        @media print {
            .page { max-width: none; padding: 0; }
            .notice { box-shadow: none; }
            .actions, .footer-note { display: none; }
        }
    </style>
</head>
<body>
<main class="page">
    <header class="brand">
        <span class="brand-icon" aria-hidden="true"><svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="7" rx="2"/><rect x="3" y="14" width="18" height="7" rx="2"/><path d="M7 6.5h.01M7 17.5h.01M12 6.5h5M12 17.5h5"/></svg></span>
        <div><div class="brand-name">ROS Plugin</div><div class="brand-context">Diagnóstico de actualización</div></div>
    </header>
    <article class="notice" aria-labelledby="conflict-title">
        <div class="notice-intro">
            <span class="status"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m10.3 3.6-8.1 14a2 2 0 0 0 1.7 3h16.2a2 2 0 0 0 1.7-3l-8.1-14a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/></svg>Actualización en pausa</span>
            <h1 id="conflict-title">Hay direcciones IP duplicadas</h1>
            <p class="intro-copy">Una dirección IPv4 o un prefijo IPv6 aparece en varios servicios. Cada asignación debe ser única para completar la actualización y reservar las direcciones de forma segura.</p>
            <div class="summary">
                <div class="summary-stat"><span class="summary-value"><?= $conflictEscape($conflictNumber($conflictTotal)) ?></span><span class="summary-label"><?= $conflictTotal === 1 ? 'dirección en conflicto' : 'direcciones en conflicto' ?></span></div>
                <span class="preserved"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 3 8 4v5c0 5-8 9-8 9s-8-4-8-9V7l8-4Z"/><path d="m8 12 3 3 5-6"/></svg>Las direcciones existentes se conservan</span>
            </div>
        </div>
        <section class="section" aria-labelledby="assignments-title">
            <div class="section-heading"><h2 id="assignments-title">Asignaciones que necesitan revisión</h2><p class="section-note">Los números identifican los servicios en UISP</p></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th scope="col">Dirección o prefijo</th><th scope="col">Familia</th><th scope="col">Servicios (ID)</th><th scope="col" class="count">Cantidad</th></tr></thead>
                    <tbody>
                    <?php foreach ($conflictRows as $conflictRow): ?>
                        <?php if (!is_array($conflictRow)) continue; ?>
                        <?php $conflictIds = is_array($conflictRow['service_ids'] ?? null) ? $conflictRow['service_ids'] : []; ?>
                        <tr>
                            <td class="address"><?= $conflictEscape($conflictRow['address'] ?? '') ?></td>
                            <td><span class="family"><?= $conflictEscape($conflictRow['family'] ?? '') ?></span></td>
                            <td><div class="service-ids"><?php foreach ($conflictIds as $conflictId): ?><span class="service-id">#<?= $conflictEscape((int) $conflictId) ?></span><?php endforeach; ?></div><?php if (!empty($conflictRow['services_truncated'])): ?><small class="row-note">Se muestra una selección de los IDs afectados.</small><?php endif; ?></td>
                            <td class="count"><?= $conflictEscape($conflictNumber(max(0, (int) ($conflictRow['service_count'] ?? count($conflictIds))))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$conflictRows): ?><tr><td colspan="4" class="empty">Descarga el diagnóstico para consultar el detalle del conflicto.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if (!empty($networkConflictDetails['conflicts_truncated'])): ?>
                <p class="limit-note"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 7h.01"/></svg><span>Se muestran <?= $conflictEscape($conflictNumber(count($conflictRows))) ?> de <?= $conflictEscape($conflictNumber($conflictTotal)) ?> direcciones en conflicto. El diagnóstico indica los límites de la información incluida.</span></p>
            <?php elseif (array_filter($conflictRows, static function ($row): bool { return is_array($row) && !empty($row['services_truncated']); })): ?>
                <p class="limit-note">La cantidad incluye todos los servicios de cada dirección; las listas de IDs pueden estar limitadas.</p>
            <?php endif; ?>
        </section>
        <section class="section" aria-labelledby="next-title">
            <div class="next-step"><h2 id="next-title">Comprueba las asignaciones antes de corregirlas</h2><p>Revisa los servicios indicados y sus cuentas en los routers para determinar a quién corresponde cada dirección. Cuando hayas resuelto las repeticiones, vuelve a comprobar para continuar la actualización.</p></div>
            <nav class="actions" aria-label="Acciones del diagnóstico">
                <a class="button button-primary" href="?page=panel"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 7v5h-5M4 17v-5h5"/><path d="M6.1 6a8 8 0 0 1 13.3 3L20 12M4 12l.6 3A8 8 0 0 0 17.9 18"/></svg>Volver a comprobar</a>
                <a class="button" href="?download=network-conflicts"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12m-5-5 5 5 5-5M5 16v5h14v-5"/></svg>Descargar diagnóstico</a>
            </nav>
            <p class="footer-note">Esta página de diagnóstico no cambia las direcciones ni realiza operaciones en los routers.</p>
        </section>
    </article>
</main>
</body>
</html>
