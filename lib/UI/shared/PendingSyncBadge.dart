// ============================================================
// PendingSyncBadge.dart
// ------------------------------------------------------------
// Muestra cuántas encuestas/tareas quedaron guardadas en el
// celular sin subir todavía (por falta de internet) y permite
// forzar la sincronización con un toque. Se actualiza solo,
// sin recargar la pantalla, porque escucha a SyncService cada
// vez que cambia el número de pendientes.
//
// Uso:
//   const PendingSyncBadge()                    → pastilla flotante
//       (se oculta cuando no hay nada pendiente, pensada para
//        superponerla sobre el mapa/pantalla principal)
//   const PendingSyncBadge(alwaysVisible: true)  → fila fija
//       (para Perfil: siempre visible, muestra "Todo sincronizado"
//        en verde cuando no hay pendientes)
// ============================================================

import 'package:flutter/material.dart';
import 'package:super_ia/Core/Constants/colorConstants.dart';
import 'package:super_ia/Core/Services/OfflineQueueService.dart';
import 'package:super_ia/Core/Services/SyncService.dart';

class PendingSyncBadge extends StatefulWidget {
  /// Si es true, el widget siempre se muestra (incluso con 0 pendientes,
  /// como confirmación de "todo sincronizado"). Pensado para Perfil.
  /// Si es false (por defecto), se oculta solo cuando no hay pendientes,
  /// pensado para flotar sobre la pantalla principal sin estorbar.
  final bool alwaysVisible;

  const PendingSyncBadge({super.key, this.alwaysVisible = false});

  @override
  State<PendingSyncBadge> createState() => _PendingSyncBadgeState();
}

class _PendingSyncBadgeState extends State<PendingSyncBadge> {
  int _pendientes = 0;
  bool _cargando = true;
  bool _sincronizando = false;

  @override
  void initState() {
    super.initState();
    _cargarConteo();
    SyncService.addListener(_onPendingChanged);
  }

  @override
  void dispose() {
    SyncService.removeListener(_onPendingChanged);
    super.dispose();
  }

  void _onPendingChanged(int pendientes) {
    if (!mounted) return;
    setState(() => _pendientes = pendientes);
  }

  Future<void> _cargarConteo() async {
    final count = await OfflineQueueService.getPendingCount();
    if (!mounted) return;
    setState(() {
      _pendientes = count;
      _cargando = false;
    });
  }

  Future<void> _sincronizarAhora() async {
    if (_sincronizando) return;
    setState(() => _sincronizando = true);

    final synced = await SyncService.syncPending();
    final pendientes = await OfflineQueueService.getPendingCount();

    if (!mounted) return;
    setState(() {
      _sincronizando = false;
      _pendientes = pendientes;
    });

    final msg = synced > 0
        ? '✅ $synced ${synced == 1 ? 'encuesta sincronizada' : 'encuestas sincronizadas'}'
        : (pendientes > 0
            ? '📵 Todavía sin conexión. $pendientes pendiente${pendientes == 1 ? '' : 's'}.'
            : 'No hay encuestas pendientes por subir.');

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(msg),
        backgroundColor: synced > 0
            ? const Color(0xFF16a34a)
            : (pendientes > 0 ? ConstantColors.warning : ConstantColors.success),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_cargando) return const SizedBox.shrink();
    if (_pendientes == 0 && !widget.alwaysVisible) return const SizedBox.shrink();

    final sinPendientes = _pendientes == 0;
    final color = sinPendientes ? ConstantColors.success : ConstantColors.warning;

    return GestureDetector(
      onTap: _sincronizarAhora,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        decoration: BoxDecoration(
          color: ConstantColors.backgroundCard,
          borderRadius: BorderRadius.circular(24),
          border: Border.all(color: color.withOpacity(0.5)),
          boxShadow: const [
            BoxShadow(color: Colors.black26, blurRadius: 8, offset: Offset(0, 4)),
          ],
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            _sincronizando
                ? SizedBox(
                    width: 16,
                    height: 16,
                    child: CircularProgressIndicator(strokeWidth: 2, color: color),
                  )
                : Icon(
                    sinPendientes ? Icons.cloud_done_rounded : Icons.cloud_upload_rounded,
                    color: color,
                    size: 18,
                  ),
            const SizedBox(width: 8),
            Text(
              _sincronizando
                  ? 'Sincronizando…'
                  : sinPendientes
                      ? 'Todo sincronizado'
                      : '$_pendientes ${_pendientes == 1 ? 'encuesta' : 'encuestas'} sin subir',
              style: TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w700),
            ),
            if (!sinPendientes && !_sincronizando) ...[
              const SizedBox(width: 6),
              Icon(Icons.refresh_rounded, color: color, size: 16),
            ],
          ],
        ),
      ),
    );
  }
}
