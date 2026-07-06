// ============================================================
// SyncService.dart
// ------------------------------------------------------------
// Sincronización automática de las encuestas/tareas guardadas
// sin conexión (ver OfflineQueueService). Escucha los cambios de
// conectividad y además reintenta cada minuto, por si la conexión
// vuelve sin disparar el evento de connectivity_plus (pasa en
// algunos dispositivos). Cuando el envío es exitoso, marca el
// registro local como sincronizado; si el servidor ya lo tenía
// (porque se reintentó dos veces), igual se marca como
// sincronizado gracias a la idempotencia por client_uuid en el
// backend (ver guardar_cliente_encuesta.php / actualizar_encuesta_completa.php).
// ============================================================

import 'dart:async';
import 'dart:convert';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:http/http.dart' as http;
import 'package:super_ia/Core/Constants/Constants.dart';
import 'package:super_ia/Core/Services/EmpresaCacheService.dart';
import 'package:super_ia/Core/Services/InstitucionesCacheService.dart';
import 'package:super_ia/Core/Services/OfflineQueueService.dart';

class SyncService {
  static bool _syncing = false;
  static Timer? _periodicTimer;
  static StreamSubscription? _connectivitySub;
  static void Function(int pendientes)? onPendingCountChanged;

  /// Widgets como PendingSyncBadge se suscriben aquí para refrescarse solos
  /// (sin polling) cada vez que cambia el número de encuestas pendientes,
  /// ya sea porque se guardó una nueva sin conexión o porque se sincronizó
  /// alguna en segundo plano.
  static final List<void Function(int pendientes)> _listeners = [];

  static void addListener(void Function(int pendientes) listener) {
    _listeners.add(listener);
  }

  static void removeListener(void Function(int pendientes) listener) {
    _listeners.remove(listener);
  }

  static bool get isSyncing => _syncing;

  /// Inicia el auto-sync. Seguro de llamar varias veces (p. ej. en cada
  /// login): cancela los listeners previos antes de crear otros nuevos.
  static void startAutoSync({void Function(int pendientes)? onPendingCount}) {
    onPendingCountChanged = onPendingCount;

    _connectivitySub?.cancel();
    _connectivitySub = Connectivity().onConnectivityChanged.listen((result) {
      if (result != ConnectivityResult.none && !_syncing) {
        syncPending();
        _refreshEmpresaCacheSilently();
        _refreshInstitucionesCacheSilently();
      }
    });

    _periodicTimer?.cancel();
    _periodicTimer = Timer.periodic(const Duration(minutes: 1), (_) async {
      if (_syncing) return;
      try {
        final result = await Connectivity().checkConnectivity();
        if (result != ConnectivityResult.none) syncPending();
      } catch (_) {
        // Ignorar errores del temporizador; se reintentará en el próximo ciclo.
      }
    });

    // Intento inicial por si ya hay pendientes de una sesión anterior.
    syncPending();
    _refreshEmpresaCacheSilently();
    _refreshInstitucionesCacheSilently();
  }

  /// Aprovecha que acaba de confirmarse conexión para refrescar en segundo
  /// plano la lista local de empresas/prospectos (ver EmpresaCacheService),
  /// que LevantarEmpresaScreen usa como respaldo cuando no hay internet.
  /// Es "silencioso": no bloquea nada ni informa error si falla, ya que es
  /// solo un refresco oportunista.
  static void _refreshEmpresaCacheSilently() {
    EmpresaCacheService.refreshCache().catchError((_) => -1);
  }

  /// Igual que arriba, pero para la lista de bancos/cooperativas (ver
  /// InstitucionesCacheService), que NuevaEncuestaScreen y
  /// EncuestaProductoScreen usan para el selector de "Institución" en
  /// cuenta de ahorros/corriente. Así la lista queda lista en el celular
  /// antes de que el asesor la necesite sin conexión.
  static void _refreshInstitucionesCacheSilently() {
    InstitucionesCacheService.refreshCache().catchError((_) => null);
  }

  static void stopAutoSync() {
    _periodicTimer?.cancel();
    _connectivitySub?.cancel();
  }

  static bool _esErrorDeConexion(Object e) {
    final s = e.toString().toLowerCase();
    return s.contains('socketexception') ||
        s.contains('failed host lookup') ||
        s.contains('clientexception') ||
        s.contains('connection refused') ||
        s.contains('no address associated') ||
        s.contains('timeoutexception') ||
        s.contains('timed out') ||
        s.contains('network is unreachable');
  }

  /// Recorre las encuestas/tareas pendientes y las reenvía al endpoint
  /// original. Devuelve cuántas se sincronizaron con éxito en esta pasada.
  static Future<int> syncPending() async {
    if (_syncing) return 0;

    final pendientes = await OfflineQueueService.getPendientes();
    if (pendientes.isEmpty) return 0;

    _syncing = true;
    int syncedCount = 0;

    try {
      for (final item in pendientes) {
        final clientUuid = item['client_uuid'] as String;
        final endpoint = item['endpoint'] as String;
        final body = Map<String, String>.from(item['body'] as Map);

        try {
          final url = Uri.parse('${Constants.apiBaseUrl}/$endpoint');
          final resp = await http.post(url, body: body).timeout(
                const Duration(seconds: 30),
              );

          Map<String, dynamic>? data;
          try {
            final decoded = json.decode(resp.body);
            if (decoded is Map) data = Map<String, dynamic>.from(decoded);
          } catch (_) {
            data = null;
          }

          if (resp.statusCode == 200 && data != null && data['status'] == 'success') {
            await OfflineQueueService.markSynced(clientUuid);
            syncedCount++;
          } else {
            // Error de negocio del servidor (no de conectividad): se cuenta
            // el intento pero se mantiene "pendiente" para no perder los
            // datos capturados en campo. Un supervisor puede revisar
            // 'ultimo_error' si el registro nunca logra sincronizarse.
            final msg = data?['message']?.toString() ??
                'Error del servidor (HTTP ${resp.statusCode})';
            await OfflineQueueService.incrementIntentos(clientUuid, error: msg);
          }
        } catch (e) {
          if (_esErrorDeConexion(e)) {
            // Seguimos sin internet: dejamos de intentar el resto de la
            // cola en esta pasada (se reintentará en el próximo ciclo).
            break;
          }
          await OfflineQueueService.incrementIntentos(clientUuid, error: e.toString());
        }
      }
    } finally {
      _syncing = false;
      await _notifyPendingCount();
    }

    return syncedCount;
  }

  /// Vuelve a leer cuántas encuestas/tareas quedan pendientes y avisa a
  /// todos los que estén escuchando (pantallas con PendingSyncBadge, etc.).
  /// Se llama sola después de sincronizar, pero también se puede invocar
  /// justo después de guardar una encuesta offline nueva, para que el
  /// contador se actualice al instante sin esperar al próximo ciclo.
  static Future<void> _notifyPendingCount() async {
    final pending = await OfflineQueueService.getPendingCount();
    onPendingCountChanged?.call(pending);
    for (final listener in List<void Function(int)>.from(_listeners)) {
      listener(pending);
    }
  }

  /// Punto de entrada público para que otras pantallas (p. ej. justo
  /// después de guardar una encuesta sin conexión) fuercen el refresco del
  /// contador de pendientes sin tener que esperar el próximo ciclo de sync.
  static Future<void> notifyPendingCountNow() => _notifyPendingCount();
}
