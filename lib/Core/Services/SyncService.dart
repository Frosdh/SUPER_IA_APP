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
import 'package:super_ia/Core/Services/CedulaIndexService.dart';
import 'package:super_ia/Core/Services/ClienteCacheService.dart';
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

  /// Se dispara cuando una encuesta guardada SIN internet se sube sola en
  /// segundo plano y tenía cliente asociado (levantamiento de empresa o
  /// encuesta general). Como en ese momento el asesor ya salió de la
  /// pantalla de la encuesta (no hay ningún diálogo "guardado con éxito"
  /// en pantalla), no hay dónde ofrecer el botón "Descargar Excel" de la
  /// solicitud de crédito. La pantalla principal (OsmMapScreen) escucha
  /// esto para mostrar un aviso con la opción de descargar en cuanto
  /// termina de sincronizar.
  static final List<void Function(String clienteId)> _syncCompletionListeners = [];

  static void addSyncCompletionListener(void Function(String clienteId) listener) {
    _syncCompletionListeners.add(listener);
  }

  static void removeSyncCompletionListener(void Function(String clienteId) listener) {
    _syncCompletionListeners.remove(listener);
  }

  /// Endpoints de encuesta/levantamiento para los que tiene sentido ofrecer
  /// la descarga del Excel de "Solicitud de Crédito" una vez sincronizados.
  static const Set<String> _endpointsConExcel = {
    'guardar_cliente_encuesta.php',
    'actualizar_encuesta_completa.php',
  };

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
        _refreshClienteCacheSilently();
        _refreshCedulaIndexSilently();
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
    _refreshClienteCacheSilently();
    _refreshCedulaIndexSilently();
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

  /// Igual que las dos anteriores, pero para la cartera de
  /// clientes/prospectos del asesor (ver ClienteCacheService), que
  /// NuevaEncuestaScreen usa para verificar una cédula (¿ya existe? ¿es
  /// cliente? ¿tiene empresa?) cuando no hay internet. Es incremental: en
  /// cada llamada solo trae lo que cambió desde el último refresco.
  static void _refreshClienteCacheSilently() {
    ClienteCacheService.refreshCache().catchError((_) => -1);
  }

  /// Refresca el índice liviano de TODA la empresa (ver CedulaIndexService),
  /// que complementa a ClienteCacheService: esa solo cubre la cartera
  /// propia del asesor, este índice permite avisar "ya existe" aunque la
  /// cédula pertenezca a otro asesor.
  static void _refreshCedulaIndexSilently() {
    CedulaIndexService.refreshCache().catchError((_) => -1);
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

            // Si esta encuesta/levantamiento tenía cliente asociado, avisar
            // para que se pueda ofrecer la descarga del Excel de la
            // solicitud de crédito ahora que ya se subió. El cliente_id
            // puede venir en la respuesta del servidor (encuesta nueva) o
            // ya venir guardado en el body (edición de una tarea existente,
            // ver NuevaEncuestaScreen._guardarEncuesta).
            //
            // OJO: si el cliente SÍ tiene empresa y esto NO es el
            // levantamiento (es la encuesta general que solo marcó "tiene
            // empresa"), todavía faltan los datos financieros (ingresos,
            // gastos, activos, pasivos) que se capturan en la tarea de
            // Levantamiento de Empresa aparte — no hay que ofrecer el Excel
            // todavía, se ofrece recién cuando ESE levantamiento sincronice.
            if (_endpointsConExcel.contains(endpoint)) {
              final tipoTarea = (body['tipo_tarea'] ?? '').trim();
              final tieneEmpresa = body['tiene_empresa'] == '1';
              final faltaLevantamiento = tieneEmpresa && tipoTarea != 'levantamiento';
              final clienteId = (data['cliente_id']?.toString() ?? body['cliente_id'] ?? '').trim();
              if (clienteId.isNotEmpty && !faltaLevantamiento) {
                for (final listener in List<void Function(String)>.from(_syncCompletionListeners)) {
                  listener(clienteId);
                }
              }
            }
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
