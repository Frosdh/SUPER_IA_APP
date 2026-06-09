<?php
/**
 * helper_ja_ids.php — Resuelve los jefe_agencia IDs que controla un usuario gerente.
 * Combina ambas vías sin depender del rol guardado en sesión:
 *   1) jefe_agencia propio  (jefe_agencia.usuario_id = usuario)
 *   2) cadena gerente_general → unidad_bancaria → agencia → jefe_agencia
 */
if (!function_exists('resolver_ja_ids')) {
    function resolver_ja_ids(PDO $pdo, ?string $usuario_id): array {
        if (!$usuario_id) return [];
        $ids = [];

        // Vía 1: jefe_agencia propio
        $st = $pdo->prepare('SELECT id FROM jefe_agencia WHERE usuario_id = ?');
        $st->execute([$usuario_id]);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);

        // Vía 2: gerente_general → unidad_bancaria → agencias → jefes de agencia
        $st = $pdo->prepare('SELECT unidad_bancaria_id FROM gerente_general WHERE usuario_id = ? LIMIT 1');
        $st->execute([$usuario_id]);
        $ub_id = $st->fetchColumn() ?: null;
        if ($ub_id) {
            $st = $pdo->prepare('SELECT ja.id FROM jefe_agencia ja
                                 JOIN agencia ag ON ag.id = ja.agencia_id
                                 WHERE ag.unidad_bancaria_id = ?');
            $st->execute([$ub_id]);
            $ids = array_merge($ids, $st->fetchAll(PDO::FETCH_COLUMN));
        }

        return array_values(array_unique($ids));
    }
}
