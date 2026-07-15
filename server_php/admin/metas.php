<?php
// ============================================================
// admin/metas.php — Asignación de Metas Diarias al Asesor (Supervisor)
// Nota: este archivo debe contener UNA sola página.
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();

require_once 'db_admin.php';   // PDO ($pdo)

$is_admin_gerente = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$is_super_admin   = isset($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true;
if (!isset($_SESSION['supervisor_logged_in']) && !$is_admin_gerente && !$is_super_admin) {
    header('Location: login.php?role=supervisor');
    exit;
}

// Para admin/super_admin no existe un supervisor.id propio: las consultas de
// abajo ya contemplan $supervisor_table_id = null como "vista global de todos
// los supervisores/asesores" (ver uso de $is_admin_gerente más abajo).
$supervisor_usuario_id = $_SESSION['supervisor_id'] ?? null;
$supervisor_nombre     = $_SESSION['supervisor_nombre'] ?? ($is_super_admin ? ($_SESSION['super_admin_nombre'] ?? 'SuperAdmin') : ($_SESSION['admin_nombre'] ?? 'Gerente'));
$supervisor_rol        = $_SESSION['supervisor_rol'] ?? ($is_super_admin ? 'SuperAdministrador' : ($_SESSION['admin_rol'] ?? 'Gerente'));

// Resolver supervisor.id
$supervisor_table_id = null;
try {
    $st = $pdo->prepare('SELECT id FROM supervisor WHERE usuario_id = ? LIMIT 1');
    $st->execute([$supervisor_usuario_id]);
    $supervisor_table_id = $st->fetchColumn() ?: null;
} catch (PDOException $e) {}

// ── Configuración de tipos de meta habilitados para el banco/cooperativa
// del supervisor (definida por el SuperAdmin en configurar_metas_banco.php).
// Si el banco no tiene fila configurada, se mantiene el comportamiento
// histórico: diaria y mensual habilitadas, semanal deshabilitada.
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS config_metas_banco (
            id VARCHAR(36) NOT NULL PRIMARY KEY,
            unidad_bancaria_id VARCHAR(36) NOT NULL,
            permite_diaria TINYINT(1) NOT NULL DEFAULT 1,
            permite_semanal TINYINT(1) NOT NULL DEFAULT 0,
            permite_mensual TINYINT(1) NOT NULL DEFAULT 1,
            dias_semana_habilitados VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5',
            actualizado_por VARCHAR(36) DEFAULT NULL,
            actualizado_at TIMESTAMP NULL DEFAULT NULL,
            UNIQUE KEY uq_config_metas_banco_ub (unidad_bancaria_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Throwable $e) {}

$banco_id_supervisor = null;
if ($supervisor_table_id) {
    try {
        $stB = $pdo->prepare("
            SELECT ag.unidad_bancaria_id
            FROM supervisor sv
            LEFT JOIN jefe_agencia ja ON ja.id = sv.jefe_agencia_id
            LEFT JOIN agencia ag ON ag.id = ja.agencia_id
            WHERE sv.id = ? LIMIT 1
        ");
        $stB->execute([$supervisor_table_id]);
        $banco_id_supervisor = $stB->fetchColumn() ?: null;
    } catch (Throwable $e) {
        $banco_id_supervisor = null;
    }
}

$cfg_metas = [
    'permite_diaria'  => 1,
    'permite_semanal' => 0,
    'permite_mensual' => 1,
];
if ($banco_id_supervisor) {
    try {
        $stC = $pdo->prepare("SELECT permite_diaria, permite_semanal, permite_mensual
                               FROM config_metas_banco WHERE unidad_bancaria_id = ? LIMIT 1");
        $stC->execute([$banco_id_supervisor]);
        $rowCfg = $stC->fetch();
        if ($rowCfg) $cfg_metas = $rowCfg;
    } catch (Throwable $e) {}
}
$cfg_metas['permite_diaria']  = (int)$cfg_metas['permite_diaria'];
$cfg_metas['permite_semanal'] = (int)$cfg_metas['permite_semanal'];
$cfg_metas['permite_mensual'] = (int)$cfg_metas['permite_mensual'];

// El SuperAdmin solo habilita/deshabilita el tipo "Semana"; los días
// concretos los elige el propio supervisor, por cada asesor, al asignar
// la meta (un asesor puede tener L-M-X y otro M-J-V en la misma semana).
$dias_habilitados_arr = [1, 2, 3, 4, 5, 6, 7];

$dias_labels_meta = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

$flash = null;

// Sidebar vars
$currentPage = 'metas';
$alertas_pendientes = 0;

// ── Validar instalación de tablas/vistas de metas ───────────
$metas_instaladas = true;
try {
    $chk = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'meta_asesor_diaria' LIMIT 1");
    $chk->execute();
    $metas_instaladas = (bool)$chk->fetchColumn();
} catch (PDOException $e) {
    // si no se puede consultar information_schema, intentaremos igual y capturaremos error
    $metas_instaladas = true;
}

if (!$metas_instaladas) {
    $dbName = '';
    try {
        $dbName = (string)($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
    } catch (PDOException $e) {
        $dbName = '';
    }

    $flash = [
        'type' => 'error',
        'msg'  => "Falta crear la tabla <b>meta_asesor_diaria</b> en la base <b>" . htmlspecialchars($dbName ?: 'corporat_base_super_ia') . "</b>. " .
                 "Ejecuta el script <b>server_php/crear_tabla_metas_asesor.sql</b> en phpMyAdmin (pestaña SQL / Importar)."
    ];
}

// ── Auto-migración de columnas y vista de metas (se ejecuta en cada carga,
//    no solo al guardar, para que la app móvil no quede rota hasta que el
//    supervisor presione "Asignar Meta Diaria") ──────────────────────────
if ($metas_instaladas) {
    // Auto-crear columnas si faltan (para evitar errores SQLSTATE[42S22])
    try {
        $cols_exist = $pdo->query("SHOW COLUMNS FROM meta_asesor_diaria")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('meta_visitas', $cols_exist)) {
            $pdo->exec("ALTER TABLE meta_asesor_diaria ADD COLUMN meta_visitas INT DEFAULT 0 AFTER meta_inversiones");
            $cols_exist[] = 'meta_visitas';
        }

        // Si una ejecución previa creó meta_cuentas_corriente_abiertas (concepto antiguo),
        // renombrarla al nuevo concepto: monto diario de créditos aprobados/desembolsados.
        if (in_array('meta_cuentas_corriente_abiertas', $cols_exist) && !in_array('meta_monto_creditos_aprobados', $cols_exist)) {
            $pdo->exec("ALTER TABLE meta_asesor_diaria CHANGE COLUMN meta_cuentas_corriente_abiertas meta_monto_creditos_aprobados INT NOT NULL DEFAULT 0");
            $cols_exist = array_values(array_diff($cols_exist, ['meta_cuentas_corriente_abiertas']));
            $cols_exist[] = 'meta_monto_creditos_aprobados';
        }

        // Nuevos objetivos del día: monto de créditos aprobados, cuentas/inversiones aprobadas
        $nuevas_metas_cols = [
            'meta_monto_creditos_aprobados'  => 'meta_visitas',
            'meta_cuentas_ahorro_abiertas'   => 'meta_monto_creditos_aprobados',
            'meta_inversiones_aprobadas'     => 'meta_cuentas_ahorro_abiertas',
        ];
        foreach ($nuevas_metas_cols as $col => $after) {
            if (!in_array($col, $cols_exist)) {
                $pdo->exec("ALTER TABLE meta_asesor_diaria ADD COLUMN $col INT NOT NULL DEFAULT 0 AFTER $after");
                $cols_exist[] = $col;
            }
        }

        // Guarda con qué modo se asignó cada fila (dia/semana/mes), para poder
        // mostrarlo en "Estado de Metas del Equipo" (columna Tipo).
        if (!in_array('origen_meta', $cols_exist)) {
            $pdo->exec("ALTER TABLE meta_asesor_diaria ADD COLUMN origen_meta VARCHAR(10) NOT NULL DEFAULT 'dia'");
            $cols_exist[] = 'origen_meta';
        }

        $cols_asesor = $pdo->query("SHOW COLUMNS FROM asesor")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('meta_visitas', $cols_asesor)) {
            $pdo->exec("ALTER TABLE asesor ADD COLUMN meta_visitas INT DEFAULT 0");
            $cols_asesor[] = 'meta_visitas';
        }
        if (in_array('meta_cuentas_corriente_abiertas', $cols_asesor) && !in_array('meta_monto_creditos_aprobados', $cols_asesor)) {
            $pdo->exec("ALTER TABLE asesor CHANGE COLUMN meta_cuentas_corriente_abiertas meta_monto_creditos_aprobados INT DEFAULT 0");
            $cols_asesor = array_values(array_diff($cols_asesor, ['meta_cuentas_corriente_abiertas']));
            $cols_asesor[] = 'meta_monto_creditos_aprobados';
        }
        foreach (array_keys($nuevas_metas_cols) as $col) {
            if (!in_array($col, $cols_asesor)) {
                $pdo->exec("ALTER TABLE asesor ADD COLUMN $col INT DEFAULT 0");
                $cols_asesor[] = $col;
            }
        }
    } catch (PDOException $e) {}

    // Auto-actualizar la vista v_meta_asesor_avance agregando los nuevos avances,
    // preservando el resto de columnas existentes (lee la definición actual y la reescribe).
    try {
        $viewCols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'v_meta_asesor_avance'")->fetchAll(PDO::FETCH_COLUMN);
        if ($viewCols) {
            // Helper: agrega columnas calculadas a la vista, insertándolas antes del FROM
            // principal y preservando el resto de la definición actual.
            $addAvanceCols = function (string $colsTemplate) use ($pdo) {
                $createRow = $pdo->query("SHOW CREATE VIEW v_meta_asesor_avance")->fetch(PDO::FETCH_ASSOC);
                $createSql = $createRow['Create View'] ?? '';
                if (!$createSql || !preg_match('/\bAS\s+(select.*)$/is', $createSql, $mSel)) return;
                $selectBody = $mSel[1];
                $patternFrom = '/\bFROM\s+(?:`[^`]+`\.)?`meta_asesor_diaria`(?:\s+(?:AS\s+)?`(\w+)`)?/i';
                if (!preg_match($patternFrom, $selectBody, $mFrom)) return;
                $alias = (!empty($mFrom[1])) ? $mFrom[1] : 'meta_asesor_diaria';
                $a = "`$alias`";
                $nuevasCols = str_replace('__A__', $a, $colsTemplate);
                $selectBody2 = preg_replace($patternFrom, $nuevasCols . ' $0', $selectBody, 1, $cntFrom);
                if ($cntFrom > 0) {
                    $pdo->exec("CREATE OR REPLACE VIEW v_meta_asesor_avance AS " . $selectBody2);
                }
            };

            $colMontoCreditos = "
  ,(SELECT COALESCE(SUM(`crm`.`monto_aprobado`),0) FROM `credito_proceso` `crm`
      WHERE `crm`.`asesor_id` = __A__.`asesor_id`
        AND `crm`.`estado_credito` IN ('aprobado','desembolsado')
        AND `crm`.`updated_at` IS NOT NULL
        AND CAST(`crm`.`updated_at` AS DATE) = __A__.`fecha`
    ) AS `avance_monto_creditos_aprobados`
";

            if (!in_array('avance_creditos_aprobados', $viewCols)) {
                // Primera migración: agrega los nuevos avances
                $addAvanceCols($colMontoCreditos . "
  ,(SELECT COUNT(0) FROM `ficha_producto` `fpca`
      WHERE `fpca`.`producto_tipo` = 'cuenta_ahorros'
        AND `fpca`.`estado_revision` = 'aprobada'
        AND `fpca`.`revision_at` IS NOT NULL
        AND CAST(`fpca`.`revision_at` AS DATE) = __A__.`fecha`
        AND (`fpca`.`asesor_id` COLLATE utf8mb4_unicode_ci = __A__.`asesor_id`
             OR `fpca`.`usuario_id` COLLATE utf8mb4_unicode_ci = (SELECT `aa2`.`usuario_id` FROM `asesor` `aa2` WHERE `aa2`.`id` = __A__.`asesor_id`))
    ) AS `avance_cuentas_ahorro_abiertas`
  ,(SELECT COUNT(0) FROM `ficha_producto` `fpi`
      WHERE `fpi`.`producto_tipo` = 'inversiones'
        AND `fpi`.`estado_revision` = 'aprobada'
        AND `fpi`.`revision_at` IS NOT NULL
        AND CAST(`fpi`.`revision_at` AS DATE) = __A__.`fecha`
        AND (`fpi`.`asesor_id` COLLATE utf8mb4_unicode_ci = __A__.`asesor_id`
             OR `fpi`.`usuario_id` COLLATE utf8mb4_unicode_ci = (SELECT `aa3`.`usuario_id` FROM `asesor` `aa3` WHERE `aa3`.`id` = __A__.`asesor_id`))
    ) AS `avance_inversiones_aprobadas`
");
                $viewCols[] = 'avance_monto_creditos_aprobados';
                $viewCols[] = 'avance_cuentas_ahorro_abiertas';
                $viewCols[] = 'avance_inversiones_aprobadas';
            } elseif (!in_array('avance_monto_creditos_aprobados', $viewCols)) {
                // Una ejecución previa creó avance_cuentas_corriente_abiertas (concepto antiguo);
                // se agrega el nuevo avance de monto diario de créditos aprobados/desembolsados.
                $addAvanceCols($colMontoCreditos);
                $viewCols[] = 'avance_monto_creditos_aprobados';
            }

            // Avance de "Visitas": tareas completadas en el día (tabla `tarea`).
            if (!in_array('avance_visitas', $viewCols)) {
                $addAvanceCols("
  ,(SELECT COUNT(0) FROM `tarea` `tv`
      WHERE `tv`.`asesor_id` = __A__.`asesor_id`
        AND `tv`.`estado` = 'completada'
        AND `tv`.`fecha_realizada` IS NOT NULL
        AND `tv`.`fecha_realizada` = __A__.`fecha`
    ) AS `avance_visitas`
");
                $viewCols[] = 'avance_visitas';
            }
        }
    } catch (PDOException $e) {}
}

// ── Guardar meta ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $supervisor_table_id && $metas_instaladas) {
    $asesor_id = $_POST['asesor_id'] ?? '';
    $fecha     = $_POST['fecha'] ?? date('Y-m-d');
    $modo_meta_raw = (string)($_POST['modo_meta'] ?? 'dia');
    $modo_meta = in_array($modo_meta_raw, ['dia', 'semana', 'mes'], true) ? $modo_meta_raw : 'dia';

    // Enforce en backend lo habilitado por el SuperAdmin para este banco,
    // por si el formulario fue manipulado en el navegador.
    $modo_permitido = [
        'dia'    => (bool)$cfg_metas['permite_diaria'],
        'semana' => (bool)$cfg_metas['permite_semanal'],
        'mes'    => (bool)$cfg_metas['permite_mensual'],
    ];
    if (empty($modo_permitido[$modo_meta])) {
        // Cae al primer modo habilitado disponible, o 'dia' si ninguno lo está
        foreach (['dia', 'semana', 'mes'] as $mOpt) {
            if ($modo_permitido[$mOpt]) { $modo_meta = $mOpt; break; }
        }
    }

    $dias_semana_post = $_POST['dias_semana'] ?? [];
    if (!is_array($dias_semana_post)) $dias_semana_post = [];
    $dias_semana_sel = array_values(array_intersect(
        array_unique(array_filter(array_map('intval', $dias_semana_post), fn($d) => $d >= 1 && $d <= 7)),
        $dias_habilitados_arr
    ));
    sort($dias_semana_sel);

    $m_enc     = (int)($_POST['meta_encuestas'] ?? 0);
    $m_cli     = (int)($_POST['meta_clientes_nuevos'] ?? 0);
    $m_cre     = (int)($_POST['meta_creditos'] ?? 0);
    $m_cah     = (int)($_POST['meta_cuenta_ahorros'] ?? 0);
    $m_cco     = (int)($_POST['meta_cuenta_corriente'] ?? 0);
    $m_inv     = (int)($_POST['meta_inversiones'] ?? 0);
    $m_vis     = (int)($_POST['meta_visitas'] ?? 0);
    $m_cca     = (int)($_POST['meta_monto_creditos_aprobados'] ?? 0);
    $m_caa     = (int)($_POST['meta_cuentas_ahorro_abiertas'] ?? 0);
    $m_inva    = (int)($_POST['meta_inversiones_aprobadas'] ?? 0);
    $obs       = trim($_POST['observaciones'] ?? '');

    if ($asesor_id) {
        try {
            // Compatibilidad: algunas instalaciones tienen supervisor_id (NOT NULL)
            $has_supervisor_id = false;
            try {
                $stCol = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'meta_asesor_diaria' AND column_name = 'supervisor_id' LIMIT 1");
                $stCol->execute();
                $has_supervisor_id = (bool)$stCol->fetchColumn();
            } catch (PDOException $e) {
                try {
                    $has_supervisor_id = (bool)$pdo->query("SHOW COLUMNS FROM meta_asesor_diaria LIKE 'supervisor_id'")->fetchColumn();
                } catch (PDOException $e2) {
                    $has_supervisor_id = false;
                }
            }


            // Compatibilidad: algunas instalaciones no tienen actualizado_at
            $has_actualizado_at = false;
            try {
                $stCol = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'meta_asesor_diaria' AND column_name = 'actualizado_at' LIMIT 1");
                $stCol->execute();
                $has_actualizado_at = (bool)$stCol->fetchColumn();
            } catch (PDOException $e) {
                // Fallback si information_schema no está disponible
                try {
                    $has_actualizado_at = (bool)$pdo->query("SHOW COLUMNS FROM meta_asesor_diaria LIKE 'actualizado_at'")->fetchColumn();
                } catch (PDOException $e2) {
                    $has_actualizado_at = false;
                }
            }

            // (La auto-creación de columnas y la migración de la vista
            // v_meta_asesor_avance ahora se ejecutan arriba, en cada carga
            // de la página, no solo al guardar.)

            // Algunas instalaciones agregaron meta_asesor_diaria.supervisor_id como NOT NULL.
            // Aun así, el filtrado se mantiene por asesor.supervisor_id.

            // Closure: guarda/actualiza la meta de un asesor para UNA fecha concreta
            $guardarMetaDia = function (string $fAsesorId, string $fFecha, array $v, string $fObs, string $fOrigen = 'dia') use ($pdo, $has_supervisor_id, $has_actualizado_at, $supervisor_table_id) {
                $cols = [
                    'asesor_id', 'fecha',
                    'meta_encuestas', 'meta_clientes_nuevos', 'meta_creditos',
                    'meta_cuenta_ahorros', 'meta_cuenta_corriente', 'meta_inversiones',
                    'meta_visitas',
                    'meta_monto_creditos_aprobados', 'meta_cuentas_ahorro_abiertas',
                    'meta_inversiones_aprobadas',
                    'observaciones', 'origen_meta'
                ];
                $vals = [
                    $fAsesorId, $fFecha,
                    $v['meta_encuestas'], $v['meta_clientes_nuevos'], $v['meta_creditos'],
                    $v['meta_cuenta_ahorros'], $v['meta_cuenta_corriente'], $v['meta_inversiones'],
                    $v['meta_visitas'],
                    $v['meta_monto_creditos_aprobados'], $v['meta_cuentas_ahorro_abiertas'],
                    $v['meta_inversiones_aprobadas'],
                    $fObs, $fOrigen
                ];
                if ($has_supervisor_id) {
                    $cols[] = 'supervisor_id';
                    $vals[] = (string)$supervisor_table_id;
                }

                $placeholders = implode(',', array_fill(0, count($cols), '?'));
                $colList = implode(', ', $cols);

                // NOTA: las metas numéricas se ACUMULAN sobre lo que ya existía para
                // ese asesor/fecha (en vez de reemplazarlo). Así, si ya había una
                // meta "por día" y luego se asigna una meta "por mes" (que se
                // reparte entre los días laborales), ambas se suman para ese día.
                $sql = "INSERT INTO meta_asesor_diaria ($colList)
                        VALUES ($placeholders)
                        ON DUPLICATE KEY UPDATE
                          estado = IF(estado IN (\"completado\",\"no_cumplido\"), estado, \"pendiente\"),
                          meta_encuestas = meta_encuestas + VALUES(meta_encuestas),
                          meta_clientes_nuevos = meta_clientes_nuevos + VALUES(meta_clientes_nuevos),
                          meta_creditos = meta_creditos + VALUES(meta_creditos),
                          meta_cuenta_ahorros = meta_cuenta_ahorros + VALUES(meta_cuenta_ahorros),
                          meta_cuenta_corriente = meta_cuenta_corriente + VALUES(meta_cuenta_corriente),
                          meta_inversiones = meta_inversiones + VALUES(meta_inversiones),
                          meta_visitas = meta_visitas + VALUES(meta_visitas),
                          meta_monto_creditos_aprobados = meta_monto_creditos_aprobados + VALUES(meta_monto_creditos_aprobados),
                          meta_cuentas_ahorro_abiertas = meta_cuentas_ahorro_abiertas + VALUES(meta_cuentas_ahorro_abiertas),
                          meta_inversiones_aprobadas = meta_inversiones_aprobadas + VALUES(meta_inversiones_aprobadas),
                          observaciones = IF(VALUES(observaciones) = '', observaciones, IF(observaciones = '' OR observaciones IS NULL, VALUES(observaciones), CONCAT(observaciones, '\\n---\\n', VALUES(observaciones)))),
                          origen_meta = VALUES(origen_meta)";

                if ($has_supervisor_id) {
                    $sql .= ", supervisor_id = VALUES(supervisor_id)";
                }
                if ($has_actualizado_at) {
                    $sql .= ", actualizado_at = CURRENT_TIMESTAMP";
                }

                $st = $pdo->prepare($sql);
                $st->execute($vals);
            };

            // Reparte un total entre $n días: el residuo se asigna a los primeros días
            $distribuirMeta = function (int $total, int $n): array {
                if ($n <= 0) return [];
                $base  = intdiv($total, $n);
                $resto = $total % $n;
                $out = [];
                for ($i = 0; $i < $n; $i++) {
                    $out[] = $base + ($i < $resto ? 1 : 0);
                }
                return $out;
            };

            $valoresIngresados = [
                'meta_encuestas'                => $m_enc,
                'meta_clientes_nuevos'           => $m_cli,
                'meta_creditos'                  => $m_cre,
                'meta_cuenta_ahorros'            => $m_cah,
                'meta_cuenta_corriente'          => $m_cco,
                'meta_inversiones'               => $m_inv,
                'meta_visitas'                   => $m_vis,
                'meta_monto_creditos_aprobados'  => $m_cca,
                'meta_cuentas_ahorro_abiertas'   => $m_caa,
                'meta_inversiones_aprobadas'     => $m_inva,
            ];

            if ($modo_meta === 'mes') {
                // ── Modo "Por Mes": los valores ingresados son TOTALES del mes.
                // Se reparten entre los días lunes a viernes que quedan desde
                // la fecha indicada hasta el fin de ese mes.
                $inicio = new DateTime($fecha);
                $fin    = new DateTime($inicio->format('Y-m-01'));
                $fin->modify('last day of this month');

                $diasLaborales = [];
                $cursor = clone $inicio;
                while ($cursor <= $fin) {
                    $dow = (int)$cursor->format('N'); // 1=lunes ... 7=domingo
                    if ($dow >= 1 && $dow <= 5) {
                        $diasLaborales[] = $cursor->format('Y-m-d');
                    }
                    $cursor->modify('+1 day');
                }

                $numDias = count($diasLaborales);

                if ($numDias === 0) {
                    $flash = ['type' => 'error', 'msg' => 'No quedan días laborales (lunes a viernes) en el mes a partir de la fecha indicada.'];
                } else {
                    // Reparte cada objetivo entre los días laborales restantes
                    $distribuciones = [];
                    foreach ($valoresIngresados as $campo => $total) {
                        $distribuciones[$campo] = $distribuirMeta($total, $numDias);
                    }

                    foreach ($diasLaborales as $idx => $fDia) {
                        $vDia = [];
                        foreach ($valoresIngresados as $campo => $total) {
                            $vDia[$campo] = $distribuciones[$campo][$idx];
                        }
                        $guardarMetaDia((string)$asesor_id, $fDia, $vDia, $obs, 'mes');
                    }

                    // Actualizar metas base del asesor con el reparto del primer día generado
                    try {
                        $stBase = $pdo->prepare("UPDATE asesor SET
                            meta_encuestas = :m1, meta_clientes_nuevos = :m2, meta_creditos = :m3,
                            meta_cuenta_ahorros = :m4, meta_cuenta_corriente = :m5, meta_inversiones = :m6,
                            meta_visitas = :m7,
                            meta_monto_creditos_aprobados = :m8, meta_cuentas_ahorro_abiertas = :m9,
                            meta_inversiones_aprobadas = :m10
                            WHERE id = :aid");
                        $stBase->execute([
                            ':m1' => $distribuciones['meta_encuestas'][0],
                            ':m2' => $distribuciones['meta_clientes_nuevos'][0],
                            ':m3' => $distribuciones['meta_creditos'][0],
                            ':m4' => $distribuciones['meta_cuenta_ahorros'][0],
                            ':m5' => $distribuciones['meta_cuenta_corriente'][0],
                            ':m6' => $distribuciones['meta_inversiones'][0],
                            ':m7' => $distribuciones['meta_visitas'][0],
                            ':m8' => $distribuciones['meta_monto_creditos_aprobados'][0],
                            ':m9' => $distribuciones['meta_cuentas_ahorro_abiertas'][0],
                            ':m10' => $distribuciones['meta_inversiones_aprobadas'][0],
                            ':aid' => $asesor_id
                        ]);
                    } catch (PDOException $e_base) {}

                    $flash = [
                        'type' => 'success',
                        'msg'  => "Meta mensual asignada correctamente. Se repartió entre <b>{$numDias} día(s) laborales</b> (lunes a viernes), del " .
                                  date('d/m/Y', strtotime($diasLaborales[0])) . " al " . date('d/m/Y', strtotime($diasLaborales[$numDias - 1])) .
                                  ". Si esos días ya tenían metas asignadas, esta cantidad se <b>sumó</b> a lo existente."
                    ];
                }
            } elseif ($modo_meta === 'semana') {
                // ── Modo "Por Semana": los valores ingresados son TOTALES de la
                // semana. Se reparten entre los días de esa semana (lunes a
                // domingo) que el supervisor marcó, limitados a los días que el
                // SuperAdmin habilitó para este banco/cooperativa.
                if (empty($dias_semana_sel)) {
                    $flash = ['type' => 'error', 'msg' => 'Selecciona al menos un día de la semana habilitado para repartir la meta semanal.'];
                } else {
                    $inicioRef = new DateTime($fecha);
                    $dowRef = (int)$inicioRef->format('N'); // 1=lunes ... 7=domingo
                    $lunesSemana = clone $inicioRef;
                    $lunesSemana->modify('-' . ($dowRef - 1) . ' days');

                    $diasSemanaFechas = [];
                    foreach ($dias_semana_sel as $dNum) {
                        $fDia = clone $lunesSemana;
                        $fDia->modify('+' . ($dNum - 1) . ' days');
                        $diasSemanaFechas[] = $fDia->format('Y-m-d');
                    }
                    sort($diasSemanaFechas);
                    $numDiasSemana = count($diasSemanaFechas);

                    $distribucionesSemana = [];
                    foreach ($valoresIngresados as $campo => $total) {
                        $distribucionesSemana[$campo] = $distribuirMeta($total, $numDiasSemana);
                    }

                    foreach ($diasSemanaFechas as $idx => $fDia) {
                        $vDia = [];
                        foreach ($valoresIngresados as $campo => $total) {
                            $vDia[$campo] = $distribucionesSemana[$campo][$idx];
                        }
                        $guardarMetaDia((string)$asesor_id, $fDia, $vDia, $obs, 'semana');
                    }

                    try {
                        $stBase = $pdo->prepare("UPDATE asesor SET
                            meta_encuestas = :m1, meta_clientes_nuevos = :m2, meta_creditos = :m3,
                            meta_cuenta_ahorros = :m4, meta_cuenta_corriente = :m5, meta_inversiones = :m6,
                            meta_visitas = :m7,
                            meta_monto_creditos_aprobados = :m8, meta_cuentas_ahorro_abiertas = :m9,
                            meta_inversiones_aprobadas = :m10
                            WHERE id = :aid");
                        $stBase->execute([
                            ':m1' => $distribucionesSemana['meta_encuestas'][0],
                            ':m2' => $distribucionesSemana['meta_clientes_nuevos'][0],
                            ':m3' => $distribucionesSemana['meta_creditos'][0],
                            ':m4' => $distribucionesSemana['meta_cuenta_ahorros'][0],
                            ':m5' => $distribucionesSemana['meta_cuenta_corriente'][0],
                            ':m6' => $distribucionesSemana['meta_inversiones'][0],
                            ':m7' => $distribucionesSemana['meta_visitas'][0],
                            ':m8' => $distribucionesSemana['meta_monto_creditos_aprobados'][0],
                            ':m9' => $distribucionesSemana['meta_cuentas_ahorro_abiertas'][0],
                            ':m10' => $distribucionesSemana['meta_inversiones_aprobadas'][0],
                            ':aid' => $asesor_id
                        ]);
                    } catch (PDOException $e_base) {}

                    $nombresDias = array_map(fn($f) => $dias_labels_meta[(int)(new DateTime($f))->format('N')], $diasSemanaFechas);
                    $flash = [
                        'type' => 'success',
                        'msg'  => "Meta semanal asignada correctamente. Se repartió entre <b>{$numDiasSemana} día(s)</b>: " .
                                  htmlspecialchars(implode(', ', $nombresDias)) .
                                  " (" . date('d/m/Y', strtotime($diasSemanaFechas[0])) . " al " . date('d/m/Y', strtotime($diasSemanaFechas[$numDiasSemana - 1])) . ")" .
                                  ". Si esos días ya tenían metas asignadas, esta cantidad se <b>sumó</b> a lo existente."
                    ];
                }
            } else {
                // ── Modo "Por Día" (comportamiento original): se guarda tal cual para la fecha indicada
                $guardarMetaDia((string)$asesor_id, $fecha, $valoresIngresados, $obs, 'dia');

                // También actualizar las metas base en la tabla asesor (como solicitó el usuario)
                try {
                    $stBase = $pdo->prepare("UPDATE asesor SET
                        meta_encuestas = :m1, meta_clientes_nuevos = :m2, meta_creditos = :m3,
                        meta_cuenta_ahorros = :m4, meta_cuenta_corriente = :m5, meta_inversiones = :m6,
                        meta_visitas = :m7,
                        meta_monto_creditos_aprobados = :m8, meta_cuentas_ahorro_abiertas = :m9,
                        meta_inversiones_aprobadas = :m10
                        WHERE id = :aid");
                    $stBase->execute([
                        ':m1' => $m_enc, ':m2' => $m_cli, ':m3' => $m_cre,
                        ':m4' => $m_cah, ':m5' => $m_cco, ':m6' => $m_inv,
                        ':m7' => $m_vis,
                        ':m8' => $m_cca, ':m9' => $m_caa, ':m10' => $m_inva,
                        ':aid' => $asesor_id
                    ]);
                } catch (PDOException $e_base) {
                    // Si las columnas no existen en asesor, ignorar o manejar silenciosamente
                }

                $flash = ['type' => 'success', 'msg' => 'Meta asignada correctamente. Si ese día ya tenía una meta, esta cantidad se <b>sumó</b> a lo existente.'];
            }
        } catch (PDOException $e) {
            $flash = ['type' => 'error', 'msg' => 'Error: ' . $e->getMessage()];
        }
    } else {
        $flash = ['type' => 'error', 'msg' => 'Debe seleccionar un asesor'];
    }
}

// ── Cargar asesores del supervisor ───────────────────────────
$asesores = [];
if ($supervisor_table_id) {
    $st = $pdo->prepare('SELECT a.id, u.nombre FROM asesor a
                         JOIN usuario u ON u.id = a.usuario_id
                         WHERE a.supervisor_id = ? AND u.activo = 1
                         ORDER BY u.nombre');
    $st->execute([$supervisor_table_id]);
    $asesores = $st->fetchAll();
} elseif ($is_admin_gerente || $is_super_admin) {
    try {
        $st = $pdo->query('SELECT a.id, u.nombre FROM asesor a JOIN usuario u ON u.id = a.usuario_id WHERE u.activo = 1 ORDER BY u.nombre');
        $asesores = $st->fetchAll();
    } catch (Throwable $_) {}
}

// ── Lista de supervisores, para el filtro visible solo en admin/gerente/super_admin ──
$supervisores_filtro_list = [];
if ($is_admin_gerente || $is_super_admin) {
    try {
        $supervisores_filtro_list = $pdo->query("
            SELECT sv.id, u.nombre
            FROM supervisor sv
            JOIN usuario u ON u.id = sv.usuario_id
            WHERE u.activo = 1
            ORDER BY u.nombre
        ")->fetchAll();
    } catch (Throwable $_) {}
}

// ── Filtros de la tabla "Estado de Metas del Equipo" ─────────
$asesor_filtro_meta = trim($_GET['asesor_filtro'] ?? '');
$asesor_ids_validos_meta = array_map(fn($a) => (string)$a['id'], $asesores);
if ($asesor_filtro_meta !== '' && !in_array($asesor_filtro_meta, $asesor_ids_validos_meta, true)) {
    $asesor_filtro_meta = '';
}

$supervisor_filtro_meta = trim($_GET['supervisor_filtro'] ?? '');
$supervisor_ids_validos_meta = array_map(fn($s) => (string)$s['id'], $supervisores_filtro_list);
if (!($is_admin_gerente || $is_super_admin) || !in_array($supervisor_filtro_meta, $supervisor_ids_validos_meta, true)) {
    $supervisor_filtro_meta = '';
}

// ── Metas del día actual con avance ──────────────────────────
$fecha_filtro = $_GET['fecha'] ?? date('Y-m-d');
$metas_hoy = [];
if (($supervisor_table_id || $is_admin_gerente || $is_super_admin) && $metas_instaladas) {
    $meta_where  = 'WHERE m.fecha = ?';
    $meta_params = [$fecha_filtro];

    if ($supervisor_table_id) {
        // Supervisor: siempre acotado a su propio equipo
        $meta_where .= ' AND a.supervisor_id = ?';
        $meta_params[] = $supervisor_table_id;
    } elseif ($supervisor_filtro_meta !== '') {
        // Admin/Gerente/SuperAdmin: filtro opcional por supervisor
        $meta_where .= ' AND a.supervisor_id = ?';
        $meta_params[] = $supervisor_filtro_meta;
    }

    if ($asesor_filtro_meta !== '') {
        $meta_where .= ' AND m.asesor_id = ?';
        $meta_params[] = $asesor_filtro_meta;
    }

    // Intentar con la vista de avances; si no existe, usar avances 0.
    $sql = "SELECT m.*, u.nombre AS asesor_nombre,
                   v.avance_encuestas, v.avance_clientes_nuevos, v.avance_creditos,
                   v.avance_cuenta_ahorros, v.avance_cuenta_corriente, v.avance_inversiones,
                   v.avance_visitas,
                   v.avance_monto_creditos_aprobados, v.avance_cuentas_ahorro_abiertas,
                   v.avance_inversiones_aprobadas
            FROM meta_asesor_diaria m
            JOIN asesor a ON a.id = m.asesor_id
            JOIN usuario u ON u.id = a.usuario_id
            LEFT JOIN v_meta_asesor_avance v ON v.meta_id = m.id
            $meta_where
            ORDER BY u.nombre";
    try {
        $st = $pdo->prepare($sql);
        $st->execute($meta_params);
        $metas_hoy = $st->fetchAll();
    } catch (PDOException $e) {
        // Fallback sin la vista
        try {
            $sql2 = "SELECT m.*, u.nombre AS asesor_nombre,
                            0 AS avance_encuestas, 0 AS avance_clientes_nuevos, 0 AS avance_creditos,
                            0 AS avance_cuenta_ahorros, 0 AS avance_cuenta_corriente, 0 AS avance_inversiones,
                            0 AS avance_visitas,
                            0 AS avance_monto_creditos_aprobados, 0 AS avance_cuentas_ahorro_abiertas,
                            0 AS avance_inversiones_aprobadas
                     FROM meta_asesor_diaria m
                     JOIN asesor a ON a.id = m.asesor_id
                     JOIN usuario u ON u.id = a.usuario_id
                     $meta_where
                     ORDER BY u.nombre";
            $st2 = $pdo->prepare($sql2);
            $st2->execute($meta_params);
            $metas_hoy = $st2->fetchAll();
        } catch (PDOException $e2) {
            $metas_hoy = [];
        }
    }

    // Auto-actualiza estado: completado si ya cumplió, no_cumplido si ya pasaron las 18:00
    // (asegura consistencia incluso si EVENT SCHEDULER está desactivado)
    if (!empty($metas_hoy)) {
        $hoy = date('Y-m-d');
        $horaActual = (int)date('H');

        $uSt = $pdo->prepare('UPDATE meta_asesor_diaria SET estado = ?, cerrado_at = NOW() WHERE id = ?');

        foreach ($metas_hoy as &$m) {
            if (($m['estado'] ?? '') !== 'pendiente') continue;

            $debeCerrar = false;
            if ($fecha_filtro < $hoy) {
                $debeCerrar = true;
            } elseif ($fecha_filtro === $hoy && $horaActual >= 18) {
                $debeCerrar = true;
            }

            $pares = [
                ['meta_encuestas','avance_encuestas'],
                ['meta_clientes_nuevos','avance_clientes_nuevos'],
                ['meta_creditos','avance_creditos'],
                ['meta_cuenta_ahorros','avance_cuenta_ahorros'],
                ['meta_cuenta_corriente','avance_cuenta_corriente'],
                ['meta_inversiones','avance_inversiones'],
                ['meta_visitas','avance_visitas'],
                ['meta_monto_creditos_aprobados','avance_monto_creditos_aprobados'],
                ['meta_cuentas_ahorro_abiertas','avance_cuentas_ahorro_abiertas'],
                ['meta_inversiones_aprobadas','avance_inversiones_aprobadas'],
            ];
            $cumplio = true;
            foreach ($pares as [$mk, $ak]) {
                $meta = (int)($m[$mk] ?? 0);
                $av   = (int)($m[$ak] ?? 0);
                if ($meta > 0 && $av < $meta) { $cumplio = false; break; }
            }

            if ($cumplio) {
                try { $uSt->execute(['completado', $m['id']]); } catch (PDOException $e) {}
                $m['estado'] = 'completado';
            } elseif ($debeCerrar) {
                try { $uSt->execute(['no_cumplido', $m['id']]); } catch (PDOException $e) {}
                $m['estado'] = 'no_cumplido';
            }
        }
        unset($m);
    }
}

// ── Filtros para el listado de tareas del equipo ─────────────
$tareas_asesor_filtro = trim($_GET['t_asesor'] ?? '');
$tareas_desde         = trim($_GET['t_desde'] ?? '');
$tareas_hasta         = trim($_GET['t_hasta'] ?? '');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tareas_desde)) {
    $tareas_desde = date('Y-m-d', strtotime('-7 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tareas_hasta)) {
    $tareas_hasta = date('Y-m-d');
}
if ($tareas_desde > $tareas_hasta) {
    // swap si el usuario invirtió el rango
    [$tareas_desde, $tareas_hasta] = [$tareas_hasta, $tareas_desde];
}

// Validar que el asesor filtrado pertenezca al supervisor
$asesor_ids_equipo = array_map(fn($a) => (string)$a['id'], $asesores);
if ($tareas_asesor_filtro !== '' && !in_array($tareas_asesor_filtro, $asesor_ids_equipo, true)) {
    $tareas_asesor_filtro = '';
}

// ── Cargar tareas del equipo (completadas + incompletas + programadas) ─
$tareas_completadas = [];
$tareas_incompletas = [];
$tareas_programadas = [];

// Para gerente: sin filtro de supervisor; para supervisor: filtrar por supervisor_id
$sup_where  = $supervisor_table_id ? 'AND a.supervisor_id = ?' : '';
$sup_params = $supervisor_table_id ? [$supervisor_table_id]    : [];

if (!empty($asesor_ids_equipo)) {
    // Asegurar que existan las columnas de trazabilidad (no destructivo)
    try {
        $has_pospuesta = (bool)$pdo->query("SHOW COLUMNS FROM tarea LIKE 'pospuesta_de_dia'")->fetchColumn();
        if (!$has_pospuesta) {
            try {
                $pdo->exec("ALTER TABLE tarea ADD COLUMN pospuesta_de_dia DATE DEFAULT NULL");
            } catch (PDOException $e) { /* ignorar si el hosting bloquea ALTER */ }
        }
    } catch (PDOException $e) { /* ignorar */ }

    try {
        $ph = implode(',', array_fill(0, count($asesor_ids_equipo), '?'));

        // --- Completadas en el rango ---
        $sqlC = "SELECT t.id, t.tipo_tarea, t.estado,
                        t.fecha_programada, t.hora_programada,
                        t.fecha_realizada, t.hora_realizada,
                        t.seleccionada_dia, t.observaciones,
                        u.nombre AS asesor_nombre,
                        cp.nombre AS cliente_nombre,
                        cp.ciudad AS cliente_ciudad
                 FROM tarea t
                 JOIN asesor a ON a.id = t.asesor_id
                 JOIN usuario u ON u.id = a.usuario_id
                 LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
                 WHERE t.asesor_id IN ($ph)
                   $sup_where
                   AND t.estado = 'completada'
                   AND t.fecha_realizada BETWEEN ? AND ?";
        $paramsC = array_merge($asesor_ids_equipo, $sup_params, [$tareas_desde, $tareas_hasta]);
        if ($tareas_asesor_filtro !== '') {
            $sqlC .= " AND t.asesor_id = ?";
            $paramsC[] = $tareas_asesor_filtro;
        }
        $sqlC .= " ORDER BY t.fecha_realizada DESC, t.hora_realizada DESC LIMIT 300";

        $stC = $pdo->prepare($sqlC);
        $stC->execute($paramsC);
        $tareas_completadas = $stC->fetchAll();

        // --- Incompletas: tareas NO completadas cuyo día efectivo (el día
        // en que realmente se esperaba hacerla) haya caído dentro del
        // rango pedido.
        //
        // Día efectivo:
        //   - Si fue pospuesta: el día original (pospuesta_de_dia)
        //   - Si no: la fecha_programada
        //
        // Regla de cuándo contar como incompleta:
        //   - Si la tarea fue POSPUESTA: cuenta inmediatamente como
        //     incompleta del día original — el solo hecho de haberla
        //     pospuesto ya indica que no se hará ese día.
        //   - Si NO fue pospuesta: solo cuenta si el día ya pasó
        //     (o si es hoy después de las 18:00), porque todavía
        //     podría cumplirse en el transcurso del día. ---
        $sqlI = "SELECT t.id, t.tipo_tarea, t.estado,
                        t.fecha_programada, t.hora_programada,
                        t.fecha_realizada, t.hora_realizada,
                        t.seleccionada_dia, t.seleccionada_at,
                        t.pospuesta_de_dia, t.observaciones,
                        u.nombre AS asesor_nombre,
                        cp.nombre AS cliente_nombre,
                        cp.ciudad AS cliente_ciudad
                 FROM tarea t
                 JOIN asesor a ON a.id = t.asesor_id
                 JOIN usuario u ON u.id = a.usuario_id
                 LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
                 WHERE t.asesor_id IN ($ph)
                   $sup_where
                   AND t.estado <> 'completada'
                   AND (
                        -- Caso pospuesta: cuenta inmediatamente contra el día original
                        (t.pospuesta_de_dia IS NOT NULL
                           AND t.pospuesta_de_dia BETWEEN ? AND ?)
                     OR
                        -- Caso no pospuesta: fecha_programada ya tiene que haber pasado
                        -- (o ya haber terminado la jornada si es hoy)
                        (t.pospuesta_de_dia IS NULL
                           AND t.fecha_programada BETWEEN ? AND ?
                           AND (
                                t.fecha_programada < CURDATE()
                             OR (t.fecha_programada = CURDATE() AND HOUR(NOW()) >= 18)
                           ))
                   )";
        $paramsI = array_merge(
            $asesor_ids_equipo,
            $sup_params,
            [$tareas_desde, $tareas_hasta, $tareas_desde, $tareas_hasta]
        );
        if ($tareas_asesor_filtro !== '') {
            $sqlI .= " AND t.asesor_id = ?";
            $paramsI[] = $tareas_asesor_filtro;
        }
        $sqlI .= " ORDER BY COALESCE(t.pospuesta_de_dia, t.fecha_programada) DESC,
                            t.hora_programada DESC
                   LIMIT 300";

        $stI = $pdo->prepare($sqlI);
        $stI->execute($paramsI);
        $tareas_incompletas = $stI->fetchAll();

        // --- Programadas: tareas NO completadas cuya fecha_programada
        // cae en el rango Y aún no ha terminado la jornada de ese día.
        // Incluye las pospuestas que fueron reasignadas a otro día
        // (porque su fecha_programada es ahora el día nuevo). ---
        $sqlP = "SELECT t.id, t.tipo_tarea, t.estado,
                        t.fecha_programada, t.hora_programada,
                        t.seleccionada_dia, t.pospuesta_de_dia,
                        t.observaciones,
                        u.nombre AS asesor_nombre,
                        cp.nombre AS cliente_nombre,
                        cp.ciudad AS cliente_ciudad
                 FROM tarea t
                 JOIN asesor a ON a.id = t.asesor_id
                 JOIN usuario u ON u.id = a.usuario_id
                 LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
                 WHERE t.asesor_id IN ($ph)
                   $sup_where
                   AND t.estado NOT IN ('completada','cancelada')
                   AND t.fecha_programada BETWEEN ? AND ?
                   AND (
                        t.fecha_programada > CURDATE()
                     OR (t.fecha_programada = CURDATE() AND HOUR(NOW()) < 18)
                   )";
        $paramsP = array_merge(
            $asesor_ids_equipo,
            $sup_params,
            [$tareas_desde, $tareas_hasta]
        );
        if ($tareas_asesor_filtro !== '') {
            $sqlP .= " AND t.asesor_id = ?";
            $paramsP[] = $tareas_asesor_filtro;
        }
        $sqlP .= " ORDER BY t.fecha_programada ASC, t.hora_programada ASC LIMIT 300";

        $stP = $pdo->prepare($sqlP);
        $stP->execute($paramsP);
        $tareas_programadas = $stP->fetchAll();
    } catch (PDOException $e) {
        // Fallback silencioso si la tabla aún no tiene las columnas nuevas
        $tareas_completadas = [];
        $tareas_incompletas = [];
        $tareas_programadas = [];
    }
}

// Alertas pendientes (para badge del sidebar)
if ($supervisor_table_id) {
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM alerta_modificacion WHERE supervisor_id=? AND vista_supervisor=0');
        $st->execute([$supervisor_table_id]);
        $alertas_pendientes = (int)$st->fetchColumn();
    } catch (PDOException $e) {
        $alertas_pendientes = 0;
    }
}

// Helper para nombre legible del tipo de tarea
function metas_tipo_tarea_label($tipo) {
    switch ($tipo) {
        case 'nueva_cita_campo':      return 'Nueva cita en campo';
        case 'nueva_cita_oficina':    return 'Nueva cita en oficina';
        case 'documentos_pendientes': return 'Recolectar documentación';
        case 'levantamiento':         return 'Levantamiento';
        default: return ucfirst(str_replace('_', ' ', (string)$tipo));
    }
}

// Helper para etiqueta + clase visual de estado.
// Para el supervisor, una tarea pospuesta cuenta como INCOMPLETA — aunque
// el asesor la vea como "pospuesta" desde la app, aquí se muestra así para
// que el supervisor vea claramente que no se hizo el día original.
function metas_estado_tarea_badge($estado, $seleccionada_dia, $fecha_programada, $pospuesta_de_dia = null) {
    $hoy = date('Y-m-d');
    if ($estado === 'completada') return ['Completada', 'est-completado'];
    if ($estado === 'cancelada')  return ['Cancelada',  'est-no_cumplido'];

    // Si la tarea tiene registro de haber sido pospuesta → INCOMPLETA
    if (!empty($pospuesta_de_dia)) {
        return ['Incompleta', 'est-no_cumplido'];
    }

    // Caso legacy (sin pospuesta_de_dia registrado): la tarea está en
    // proceso pero con seleccionada_dia distinta a hoy → INCOMPLETA
    if ($estado === 'en_proceso' && $seleccionada_dia && $seleccionada_dia !== $hoy) {
        return ['Incompleta', 'est-no_cumplido'];
    }
    if ($estado === 'en_proceso') return ['En proceso', 'est-pendiente'];
    if ($estado === 'postergada') return ['Postergada', 'est-pendiente'];
    if ($estado === 'programada') return ['Programada', 'est-pendiente'];
    if ($estado === 'pendiente')  return ['Pendiente',  'est-pendiente'];
    return [$estado ?: '—', 'est-pendiente'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Metas del Equipo — Super_IA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="supervisor_layout.css?v=<?= time() ?>">
    <style>
        /* Layout heredado de supervisor_layout.css — no se necesitan overrides */
    </style>
</head>
<body>

<?php
$navTitle = ''; $navIcon = ''; $navSubtitle = '';
if ($is_super_admin) {
    $currentPage = 'metas';
    require_once '_sidebar_super_admin.php';
} elseif ($is_admin_gerente) {
    $currentPage = 'metas';
    require_once '_sidebar_gerente.php';
} else {
    require_once '_sidebar_supervisor.php';
}
?>

<?php if ($flash): ?>
    <div class="flash flash-<?= htmlspecialchars($flash['type']) ?>"><?= $flash['msg'] ?></div>
<?php endif; ?>

        <!-- WELCOME BANNER -->
        <div class="welcome-card mb-4" style="flex-wrap:wrap; row-gap:18px;">
            <div>
                <h1>Metas y Seguimiento</h1>
                <p>Gestiona los objetivos diarios de tu equipo y monitorea su progreso en tiempo real.</p>
            </div>
            <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
                <div class="welcome-meta">
                    <div class="welcome-meta-item">
                        <div class="wm-num"><?= count($metas_hoy) ?></div>
                        <div class="wm-lbl">Metas Hoy</div>
                    </div>
                    <div class="welcome-meta-item">
                        <div class="wm-num"><?= count($tareas_incompletas) ?></div>
                        <div class="wm-lbl">Pendientes</div>
                    </div>
                </div>
                <a href="encuestas.php" class="btn-save px-4" style="color:var(--brand-navy-deep) !important; text-decoration:none;">
                    <i class="fas fa-clipboard-list"></i> Ver todas las Encuestas
                </a>
            </div>
        </div>

        <?php
        $modos_habilitados_ui = [];
        if ($cfg_metas['permite_diaria'])  $modos_habilitados_ui[] = 'dia';
        if ($cfg_metas['permite_semanal']) $modos_habilitados_ui[] = 'semana';
        if ($cfg_metas['permite_mensual']) $modos_habilitados_ui[] = 'mes';
        if (empty($modos_habilitados_ui)) $modos_habilitados_ui = ['dia'];
        $modo_default_ui = $modos_habilitados_ui[0];
        ?>
        <?php if (!$is_admin_gerente && !$is_super_admin): ?>
        <!-- FORMULARIO ASIGNAR META -->
        <div class="section-card mb-4">
            <div class="section-header">
                <h5><i class="fas fa-plus-circle text-success"></i> Asignar Meta Diaria</h5>
                <span class="badge-premium badge-navy-soft">Nueva Asignación</span>
            </div>
            <div class="section-body">
                <form method="post" action="metas.php" id="formAsignarMeta">
                    <div class="row g-4">
                        <div class="col-12">
                            <div class="d-flex flex-wrap align-items-center gap-3 p-3" style="background:#f8fafc; border-radius:14px; border:1px solid #e2e8f0;">
                                <span class="form-label fw-bold small text-muted mb-0 text-uppercase"><i class="fas fa-sliders-h me-1"></i> Tipo de Asignación:</span>
                                <?php if (count($modos_habilitados_ui) > 1): ?>
                                <div class="btn-group" role="group" aria-label="Tipo de asignación">
                                    <?php if ($cfg_metas['permite_diaria']): ?>
                                    <input type="radio" class="btn-check" name="modo_meta" id="modoMetaDia" value="dia" <?= $modo_default_ui === 'dia' ? 'checked' : '' ?> autocomplete="off" onchange="cambiarModoMeta()">
                                    <label class="btn btn-outline-primary" for="modoMetaDia"><i class="fas fa-calendar-day me-1"></i> Por Día</label>
                                    <?php endif; ?>

                                    <?php if ($cfg_metas['permite_semanal']): ?>
                                    <input type="radio" class="btn-check" name="modo_meta" id="modoMetaSemana" value="semana" <?= $modo_default_ui === 'semana' ? 'checked' : '' ?> autocomplete="off" onchange="cambiarModoMeta()">
                                    <label class="btn btn-outline-primary" for="modoMetaSemana"><i class="fas fa-calendar-week me-1"></i> Por Semana</label>
                                    <?php endif; ?>

                                    <?php if ($cfg_metas['permite_mensual']): ?>
                                    <input type="radio" class="btn-check" name="modo_meta" id="modoMetaMes" value="mes" <?= $modo_default_ui === 'mes' ? 'checked' : '' ?> autocomplete="off" onchange="cambiarModoMeta()">
                                    <label class="btn btn-outline-primary" for="modoMetaMes"><i class="fas fa-calendar-alt me-1"></i> Por Mes</label>
                                    <?php endif; ?>
                                </div>
                                <?php else: ?>
                                    <input type="hidden" name="modo_meta" value="<?= htmlspecialchars($modo_default_ui) ?>">
                                    <span class="badge-premium badge-navy-soft">
                                        <?= ['dia' => 'Por Día', 'semana' => 'Por Semana', 'mes' => 'Por Mes'][$modo_default_ui] ?>
                                    </span>
                                    <small class="text-muted">(El SuperAdmin solo habilitó este tipo de meta para tu banco/cooperativa)</small>
                                <?php endif; ?>
                                <div id="hintModoMes" class="small text-muted mb-0" style="display:none; flex:1 1 260px;">
                                    <i class="fas fa-circle-info text-warning me-1"></i>
                                    Los valores de <b>Objetivos</b> serán el <b>total del mes</b> y se repartirán automáticamente entre los días <b>lunes a viernes</b> restantes del mes, a partir de la fecha indicada. Si algún día ya tenía una meta asignada, lo nuevo se <b>suma</b> a lo existente (no lo reemplaza).
                                </div>
                                <div id="hintModoSemana" class="small text-muted mb-0" style="display:none; flex:1 1 260px;">
                                    <i class="fas fa-circle-info text-warning me-1"></i>
                                    Los valores de <b>Objetivos</b> serán el <b>total de la semana</b> y se repartirán entre los días marcados abajo. Si algún día ya tenía una meta asignada, lo nuevo se <b>suma</b> a lo existente (no lo reemplaza).
                                </div>
                            </div>
                        </div>
                        <?php if ($cfg_metas['permite_semanal']): ?>
                        <div class="col-12" id="boxDiasSemanaMeta" style="display:none;">
                            <div class="p-3" style="background:#f0f5ff; border-radius:14px; border:1px solid #dbeafe;">
                                <div class="form-label fw-bold small text-muted mb-2 text-uppercase"><i class="fas fa-calendar-week me-1"></i> Días de la semana a repartir (elige los días de ESTE asesor)</div>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php foreach ($dias_labels_meta as $dNum => $dLabel): ?>
                                        <label style="display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:20px; border:1.5px solid #cbd5e1; background:#fff; font-size:13px; font-weight:600; color:#334155; cursor:pointer;">
                                            <input type="checkbox" name="dias_semana[]" value="<?= $dNum ?>" <?= in_array($dNum, [1,2,3,4,5], true) ? 'checked' : '' ?> style="accent-color:var(--brand-navy);">
                                            <?= $dLabel ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <small class="text-muted d-block mt-2"><i class="fas fa-circle-info"></i> Cada asesor puede tener días distintos: marca solo los días en que ESTE asesor trabajará su meta semanal.</small>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="col-md-6">
                            <div class="form-section h-100">
                                <h4><i class="fas fa-user-tie"></i> Información General</h4>
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label fw-bold small text-muted">Asesor a Cargo</label>
                                        <select name="asesor_id" class="form-select form-select-lg shadow-sm" required style="border-radius:12px; border-color:#e2e8f0;">
                                            <option value="">-- Selecciona --</option>
                                            <?php foreach ($asesores as $a): ?>
                                                <option value="<?= htmlspecialchars($a['id']) ?>"><?= htmlspecialchars($a['nombre']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-bold small text-muted" id="lblFechaAplicacion">Fecha de Aplicación</label>
                                        <input type="date" name="fecha" id="inputFechaMeta" class="form-control form-control-lg shadow-sm" value="<?= htmlspecialchars($fecha_filtro) ?>" required style="border-radius:12px; border-color:#e2e8f0;">
                                        <small class="text-muted" id="smallFechaAyuda" style="display:none;"></small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-section h-100">
                                <h4 id="lblObjetivosTitulo"><i class="fas fa-bullseye"></i> Objetivos del Día</h4>
                                <div class="row g-3">
                                    <div class="col-4">
                                        <label class="form-label small fw-bold text-muted"><i class="fas fa-poll me-1"></i> Encuestas</label>
                                        <input type="number" name="meta_encuestas" class="form-control shadow-sm" min="0" value="0" style="border-radius:10px;">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label small fw-bold text-muted"><i class="fas fa-user-plus me-1"></i> Clientes</label>
                                        <input type="number" name="meta_clientes_nuevos" class="form-control shadow-sm" min="0" value="0" style="border-radius:10px;">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label small fw-bold text-muted"><i class="fas fa-hand-holding-usd me-1"></i> Créditos</label>
                                        <input type="number" name="meta_creditos" class="form-control shadow-sm" min="0" value="0" style="border-radius:10px;">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label small fw-bold text-muted"><i class="fas fa-piggy-bank me-1"></i> Ahorros</label>
                                        <input type="number" name="meta_cuenta_ahorros" class="form-control shadow-sm" min="0" value="0" style="border-radius:10px;">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label small fw-bold text-muted"><i class="fas fa-money-check-alt me-1"></i> C. Corriente</label>
                                        <input type="number" name="meta_cuenta_corriente" class="form-control shadow-sm" min="0" value="0" style="border-radius:10px;">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label small fw-bold text-muted"><i class="fas fa-chart-line me-1"></i> Inversiones</label>
                                        <input type="number" name="meta_inversiones" class="form-control shadow-sm" min="0" value="0" style="border-radius:10px;">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label small fw-bold text-muted"><i class="fas fa-walking me-1"></i> Visitas</label>
                                        <input type="number" name="meta_visitas" class="form-control shadow-sm" min="0" value="0" style="border-radius:10px;">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label small fw-bold text-muted" id="lblMontoCreditos"><i class="fas fa-dollar-sign me-1"></i> Monto Créditos Aprob. ($/día)</label>
                                        <input type="number" name="meta_monto_creditos_aprobados" class="form-control shadow-sm" min="0" value="0" placeholder="Ej: 5000" style="border-radius:10px;">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label small fw-bold text-muted"><i class="fas fa-coins me-1"></i> Ctas. Ahorro Abiertas</label>
                                        <input type="number" name="meta_cuentas_ahorro_abiertas" class="form-control shadow-sm" min="0" value="0" style="border-radius:10px;">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label small fw-bold text-muted"><i class="fas fa-chart-pie me-1"></i> Inversiones Aprobadas</label>
                                        <input type="number" name="meta_inversiones_aprobadas" class="form-control shadow-sm" min="0" value="0" style="border-radius:10px;">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-group px-2">
                                <label class="form-label fw-bold small text-muted">Observaciones y Notas para el Asesor</label>
                                <textarea name="observaciones" class="form-control shadow-sm" rows="2" placeholder="Instrucciones específicas..." style="border-radius:12px; border-color:#e2e8f0;"></textarea>
                            </div>
                        </div>
                        <div class="col-12 text-center pt-2">
                            <button type="submit" class="btn-save px-5 shadow" style="height:50px; border-radius:15px; font-weight:800; letter-spacing:0.5px;">
                                <i class="fas fa-save me-2"></i> <span id="lblBotonGuardar">ESTABLECER METAS</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>


        <!-- METAS ACTUALES -->
        <div class="section-card mb-4">
            <div class="section-header" style="flex-wrap:wrap; row-gap:10px;">
                <h5><i class="fas fa-list-check text-primary"></i> Estado de Metas del Equipo</h5>
                <form method="get" class="d-flex align-items-center gap-3 flex-wrap">
                    <?php if ($is_admin_gerente || $is_super_admin): ?>
                    <div class="d-flex align-items-center gap-2">
                        <label class="small fw-bold text-muted text-nowrap m-0"><i class="fas fa-user-tie"></i> Supervisor:</label>
                        <select name="supervisor_filtro" onchange="this.form.submit()" class="form-select form-select-sm shadow-sm" style="width:auto; border-radius:8px;">
                            <option value="">Todos</option>
                            <?php foreach ($supervisores_filtro_list as $sv): ?>
                                <option value="<?= htmlspecialchars($sv['id']) ?>" <?= $supervisor_filtro_meta === $sv['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($sv['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="d-flex align-items-center gap-2">
                        <label class="small fw-bold text-muted text-nowrap m-0"><i class="fas fa-user"></i> Asesor:</label>
                        <select name="asesor_filtro" onchange="this.form.submit()" class="form-select form-select-sm shadow-sm" style="width:auto; border-radius:8px;">
                            <option value="">Todos</option>
                            <?php foreach ($asesores as $a): ?>
                                <option value="<?= htmlspecialchars($a['id']) ?>" <?= $asesor_filtro_meta === $a['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($a['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <label class="small fw-bold text-muted text-nowrap m-0"><i class="fas fa-calendar-day"></i> Consultar Fecha:</label>
                        <input type="date" name="fecha" value="<?= htmlspecialchars($fecha_filtro) ?>" onchange="this.form.submit()" class="form-control form-control-sm shadow-sm" style="width:auto; border-radius:8px;">
                    </div>
                </form>
            </div>
            <div class="section-body p-0">

            <?php if (empty($metas_hoy)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No hay metas asignadas para esta fecha.</p>
                </div>
            <?php else: ?>
                <div class="table-premium-container">
                    <table class="table-premium">
                        <thead>
                            <tr>
                                <th>Asesor</th>
                                <th class="text-center">Tipo</th>
                                <th class="text-center">Encuestas</th>
                                <th class="text-center">Clientes</th>
                                <th class="text-center">Créditos</th>
                                <th class="text-center">Ahorros</th>
                                <th class="text-center">C. Corriente</th>
                                <th class="text-center">Inversiones</th>
                                <th class="text-center">Visitas</th>
                                <th class="text-center">Monto Créditos Aprob.</th>
                                <th class="text-center">Ctas. Ahorro Abiertas</th>
                                <th class="text-center">Inversiones Aprob.</th>
                                <th class="text-center">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($metas_hoy as $m): ?>
                            <?php
                            $estClass = 'badge-' . ($m['estado'] === 'completado' ? 'success' : ($m['estado'] === 'no_cumplido' ? 'danger' : 'warning')) . '-soft';
                            $estLabel = ['pendiente'=>'Pendiente','completado'=>'Completado','no_cumplido'=>'No cumplido'][$m['estado']] ?? $m['estado'];

                            $origenLabels = ['dia' => ['Diaria', 'badge-navy-soft'], 'semana' => ['Semanal', 'badge-warning-soft'], 'mes' => ['Mensual', 'badge-success-soft']];
                            [$origenLabel, $origenClass] = $origenLabels[$m['origen_meta'] ?? 'dia'] ?? ['Diaria', 'badge-navy-soft'];

                            $fmtProgress = function($av, $meta) {
                                $av = (int)$av; $meta = (int)$meta;
                                if ($meta <= 0) return '<span class="text-muted small">—</span>';
                                $pct = min(100, round($av * 100 / $meta));
                                $color = $av >= $meta ? 'var(--brand-success)' : 'var(--brand-warning)';
                                return '
                                    <div class="d-flex flex-column align-items-center" style="min-width:80px;">
                                        <div class="fw-bold mb-1" style="font-size:12px; color:'.$color.'">'.$av.'/'.$meta.'</div>
                                        <div class="progress-container" style="height:4px;">
                                            <div class="progress-bar-fill" style="width:'.$pct.'%; background:'.$color.'"></div>
                                        </div>
                                    </div>';
                            };
                            $fmtProgressMoney = function($av, $meta) {
                                $av = (float)$av; $meta = (float)$meta;
                                if ($meta <= 0) return '<span class="text-muted small">—</span>';
                                $pct = min(100, round($av * 100 / $meta));
                                $color = $av >= $meta ? 'var(--brand-success)' : 'var(--brand-warning)';
                                return '
                                    <div class="d-flex flex-column align-items-center" style="min-width:90px;">
                                        <div class="fw-bold mb-1" style="font-size:12px; color:'.$color.'">$'.number_format($av,0).'/$'.number_format($meta,0).'</div>
                                        <div class="progress-container" style="height:4px;">
                                            <div class="progress-bar-fill" style="width:'.$pct.'%; background:'.$color.'"></div>
                                        </div>
                                    </div>';
                            };
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-bold text-navy"><?= htmlspecialchars($m['asesor_nombre']) ?></div>
                                    <small class="text-muted" style="font-size:10px;">ID: <?= $m['asesor_id'] ?></small>
                                </td>
                                <td class="text-center"><span class="badge-premium <?= $origenClass ?>"><?= $origenLabel ?></span></td>
                                <td class="text-center"><?= $fmtProgress($m['avance_encuestas'], $m['meta_encuestas']) ?></td>
                                <td class="text-center"><?= $fmtProgress($m['avance_clientes_nuevos'], $m['meta_clientes_nuevos']) ?></td>
                                <td class="text-center"><?= $fmtProgress($m['avance_creditos'], $m['meta_creditos']) ?></td>
                                <td class="text-center"><?= $fmtProgress($m['avance_cuenta_ahorros'], $m['meta_cuenta_ahorros']) ?></td>
                                <td class="text-center"><?= $fmtProgress($m['avance_cuenta_corriente'], $m['meta_cuenta_corriente']) ?></td>
                                <td class="text-center"><?= $fmtProgress($m['avance_inversiones'], $m['meta_inversiones']) ?></td>
                                <td class="text-center"><?= $fmtProgress($m['avance_visitas'], $m['meta_visitas']) ?></td>
                                <td class="text-center"><?= $fmtProgressMoney($m['avance_monto_creditos_aprobados'] ?? 0, $m['meta_monto_creditos_aprobados'] ?? 0) ?></td>
                                <td class="text-center"><?= $fmtProgress($m['avance_cuentas_ahorro_abiertas'] ?? 0, $m['meta_cuentas_ahorro_abiertas'] ?? 0) ?></td>
                                <td class="text-center"><?= $fmtProgress($m['avance_inversiones_aprobadas'] ?? 0, $m['meta_inversiones_aprobadas'] ?? 0) ?></td>
                                <td class="text-center">
                                    <span class="badge-premium <?= $estClass ?>"><?= $estLabel ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            </div>
        </div>

        <!-- ── TAREAS DEL EQUIPO ── -->
        <!-- Nota (2026-07): el detalle actividad-por-actividad (tabla plana +
             cajas Programadas/Completadas) se movió a encuestas.php, que
             además permite ver cada encuesta pregunta por pregunta tal cual
             se llenó en el celular y confirma si ya se subió al servidor.
             Aquí solo queda un resumen rápido con acceso directo. -->
        <div class="section-card">
            <div class="section-header">
                <h5><i class="fas fa-tasks text-purple"></i> Actividad del Equipo (últimos <?= (new DateTime($tareas_hasta))->diff(new DateTime($tareas_desde))->days + 1 ?> días)</h5>
            </div>
            <div class="section-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="p-4 border rounded-4 bg-light text-center">
                            <div class="fw-800" style="font-size:26px; color:#059669;"><?= count($tareas_completadas) ?></div>
                            <div class="small text-muted fw-bold text-uppercase">Completadas</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-4 border rounded-4 bg-light text-center">
                            <div class="fw-800" style="font-size:26px; color:#3b82f6;"><?= count($tareas_programadas) ?></div>
                            <div class="small text-muted fw-bold text-uppercase">Programadas</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-4 border rounded-4 bg-light text-center">
                            <div class="fw-800" style="font-size:26px; color:#dc2626;"><?= count($tareas_incompletas) ?></div>
                            <div class="small text-muted fw-bold text-uppercase">Incompletas / Pospuestas</div>
                        </div>
                    </div>
                </div>
                <p class="text-muted small mt-3 mb-0">
                    <i class="fas fa-circle-info"></i>
                    Para ver cada encuesta y levantamiento de empresa en detalle (pregunta por pregunta, con filtro de fecha, asesor, tipo y estado), entra a
                    <a href="encuestas.php">Encuestas del Equipo</a>.
                </p>
            </div>
        </div>
    </div>
    </div><!-- /.content-area -->
</div><!-- /.main-content -->

<script>
function getModoMetaActual() {
    const checked = document.querySelector('input[name="modo_meta"]:checked');
    if (checked) return checked.value;
    const hidden = document.querySelector('input[type="hidden"][name="modo_meta"]');
    if (hidden) return hidden.value;
    return 'dia';
}

function cambiarModoMeta() {
    if (!document.getElementById('formAsignarMeta')) return; // admin/super_admin no asignan metas

    const modo = getModoMetaActual();
    const esMes = modo === 'mes';
    const esSemana = modo === 'semana';

    const hintMes = document.getElementById('hintModoMes');
    const hintSemana = document.getElementById('hintModoSemana');
    const boxDias = document.getElementById('boxDiasSemanaMeta');
    const lblFecha = document.getElementById('lblFechaAplicacion');
    const lblTitulo = document.getElementById('lblObjetivosTitulo');
    const lblBoton = document.getElementById('lblBotonGuardar');
    const smallFecha = document.getElementById('smallFechaAyuda');
    const lblMonto = document.getElementById('lblMontoCreditos');

    if (hintMes) hintMes.style.display = esMes ? 'block' : 'none';
    if (hintSemana) hintSemana.style.display = esSemana ? 'block' : 'none';
    if (boxDias) boxDias.style.display = esSemana ? 'block' : 'none';

    if (lblFecha) lblFecha.textContent = esMes ? 'Fecha de Inicio (dentro del mes)' : (esSemana ? 'Fecha dentro de la semana' : 'Fecha de Aplicación');
    if (lblTitulo) lblTitulo.innerHTML = esMes
        ? '<i class="fas fa-bullseye"></i> Objetivos del Mes (Totales)'
        : (esSemana ? '<i class="fas fa-bullseye"></i> Objetivos de la Semana (Totales)' : '<i class="fas fa-bullseye"></i> Objetivos del Día');
    if (lblBoton) lblBoton.textContent = esMes ? 'ESTABLECER METAS DEL MES' : (esSemana ? 'ESTABLECER METAS DE LA SEMANA' : 'ESTABLECER METAS');

    if (lblMonto) lblMonto.innerHTML = esMes
        ? '<i class="fas fa-dollar-sign me-1"></i> Monto Créditos Aprob. ($/mes)'
        : (esSemana ? '<i class="fas fa-dollar-sign me-1"></i> Monto Créditos Aprob. ($/semana)' : '<i class="fas fa-dollar-sign me-1"></i> Monto Créditos Aprob. ($/día)');

    if (smallFecha) {
        if (esMes) {
            smallFecha.style.display = 'block';
            actualizarResumenMes();
        } else {
            smallFecha.style.display = 'none';
        }
    }
}

function actualizarResumenMes() {
    const smallFecha = document.getElementById('smallFechaAyuda');
    const inputFecha = document.getElementById('inputFechaMeta');
    if (!inputFecha.value) {
        smallFecha.textContent = '';
        return;
    }
    const [y, m, d] = inputFecha.value.split('-').map(Number);
    const inicio = new Date(y, m - 1, d);
    const fin = new Date(y, m, 0); // último día del mes

    let dias = 0;
    const cursor = new Date(inicio);
    while (cursor <= fin) {
        const dow = cursor.getDay(); // 0=domingo ... 6=sábado
        if (dow >= 1 && dow <= 5) dias++;
        cursor.setDate(cursor.getDate() + 1);
    }
    const opts = { day: '2-digit', month: '2-digit', year: 'numeric' };
    smallFecha.innerHTML = '<i class="fas fa-info-circle"></i> Se repartirán los objetivos entre <b>' + dias + '</b> día(s) laborales: del ' +
        inicio.toLocaleDateString('es-ES', opts) + ' al ' + fin.toLocaleDateString('es-ES', opts) + '.';
}

document.addEventListener('DOMContentLoaded', function () {
    const inputFecha = document.getElementById('inputFechaMeta');
    if (inputFecha) {
        inputFecha.addEventListener('change', function () {
            if (getModoMetaActual() === 'mes') actualizarResumenMes();
        });
    }
    cambiarModoMeta();
});
</script>

</body>
</html>
