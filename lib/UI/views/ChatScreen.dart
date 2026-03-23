import 'dart:async';
import 'package:flutter/material.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';
import 'package:fu_uber/Core/Models/ChatMessage.dart';
import 'package:fu_uber/Core/Preferences/AuthPrefs.dart';
import 'package:fu_uber/Core/Preferences/DriverPrefs.dart';
import 'package:fu_uber/Core/Services/ChatService.dart';
import 'package:url_launcher/url_launcher.dart';

class ChatScreen extends StatefulWidget {
  static const String route = '/chat';

  const ChatScreen({Key? key}) : super(key: key);

  @override
  State<ChatScreen> createState() => _ChatScreenState();
}

class _ChatScreenState extends State<ChatScreen> {
  // ── Args desde Navigator ──────────────────────────
  late int _viajeId;
  late String _remitente;   // 'pasajero' | 'conductor'
  late String _nombreOtro;  // nombre del otro extremo
  late String _telefonoOtro;
  String _miNombre = '';    // mi propio nombre (para la notificación push)

  // ── Estado del chat ───────────────────────────────
  final List<ChatMessage> _mensajes = [];
  int _ultimoId = 0;
  bool _enviando = false;
  bool _inicialCargado = false;
  Timer? _pollingTimer;

  final TextEditingController _inputCtrl = TextEditingController();
  final ScrollController _scrollCtrl = ScrollController();

  // ── Mensajes rápidos predefinidos ─────────────────
  static const List<String> _rapidos = [
    'Estoy en camino 🚗',
    'Ya llegué 📍',
    '5 minutos más ⏱️',
    'Esperando en la entrada 🏠',
    '¿Dónde estás? 📲',
    'Ok, entendido ✅',
  ];

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (!_inicialCargado) {
      _inicialCargado = true;
      final args =
          ModalRoute.of(context)?.settings.arguments as Map<String, dynamic>?;
      _viajeId      = args?['viaje_id']     as int?    ?? 0;
      _remitente    = args?['remitente']    as String? ?? 'pasajero';
      _nombreOtro   = args?['nombre_otro']  as String? ?? 'Conductor';
      _telefonoOtro = args?['telefono_otro'] as String? ?? '';

      // Cargar mi nombre para enviarlo en la notificación push
      _cargarMiNombre();

      _cargarMensajes();
      _pollingTimer =
          Timer.periodic(const Duration(seconds: 4), (_) => _cargarMensajes());
    }
  }

  @override
  void dispose() {
    _pollingTimer?.cancel();
    _inputCtrl.dispose();
    _scrollCtrl.dispose();
    super.dispose();
  }

  // ── Mi nombre para notificaciones push ───────────────────
  Future<void> _cargarMiNombre() async {
    try {
      if (_remitente == 'conductor') {
        final nombre = await DriverPrefs.getDriverName();
        if (mounted && nombre.isNotEmpty) setState(() => _miNombre = nombre);
      } else {
        final nombre = await AuthPrefs.getUserName();
        if (mounted && nombre.isNotEmpty) setState(() => _miNombre = nombre);
      }
    } catch (_) {}
  }

  // ── Carga/polling ─────────────────────────────────

  Future<void> _cargarMensajes() async {
    if (_viajeId <= 0) return;
    final nuevos = await ChatService.obtenerMensajes(
      viajeId: _viajeId,
      ultimoId: _ultimoId,
    );
    if (!mounted || nuevos.isEmpty) return;
    setState(() {
      _mensajes.addAll(nuevos);
      _ultimoId = _mensajes.last.id;
    });
    _scrollAlFinal();
  }

  // ── Envío ─────────────────────────────────────────

  Future<void> _enviar(String texto) async {
    final msg = texto.trim();
    if (msg.isEmpty || _enviando || _viajeId <= 0) return;
    _inputCtrl.clear();
    setState(() => _enviando = true);

    // Mostrar el mensaje localmente de inmediato (optimistic UI)
    final local = ChatMessage(
      id:        -DateTime.now().millisecondsSinceEpoch, // id temporal negativo
      remitente: _remitente,
      mensaje:   msg,
      fecha:     DateTime.now().toIso8601String(),
    );
    setState(() => _mensajes.add(local));
    _scrollAlFinal();

    await ChatService.enviarMensaje(
      viajeId:         _viajeId,
      remitente:       _remitente,
      mensaje:         msg,
      nombreRemitente: _miNombre,
    );

    if (mounted) setState(() => _enviando = false);
  }

  void _scrollAlFinal() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_scrollCtrl.hasClients) {
        _scrollCtrl.animateTo(
          _scrollCtrl.position.maxScrollExtent,
          duration: const Duration(milliseconds: 250),
          curve: Curves.easeOut,
        );
      }
    });
  }

  // ── Build ─────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: ConstantColors.backgroundDark,
      appBar: _buildAppBar(),
      body: Column(
        children: [
          // Lista de mensajes
          Expanded(child: _buildListaMensajes()),
          // Mensajes rápidos
          _buildMensajesRapidos(),
          // Input
          _buildInput(),
        ],
      ),
    );
  }

  PreferredSizeWidget _buildAppBar() {
    return AppBar(
      backgroundColor: ConstantColors.backgroundCard,
      elevation: 0,
      leading: IconButton(
        icon: const Icon(Icons.arrow_back_ios_new_rounded, color: Colors.white),
        onPressed: () => Navigator.pop(context),
      ),
      title: Row(children: [
        CircleAvatar(
          radius: 18,
          backgroundColor: ConstantColors.primaryViolet.withOpacity(0.2),
          child: Icon(
            _remitente == 'pasajero'
                ? Icons.directions_car_rounded
                : Icons.person_rounded,
            color: ConstantColors.primaryViolet,
            size: 18,
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                _nombreOtro,
                style: TextStyle(
                  color: ConstantColors.textWhite,
                  fontSize: 15,
                  fontWeight: FontWeight.w700,
                ),
              ),
              Text(
                _remitente == 'pasajero' ? 'Tu conductor' : 'Pasajero',
                style: TextStyle(
                    color: ConstantColors.textGrey, fontSize: 11),
              ),
            ],
          ),
        ),
      ]),
      // Botón llamar (si hay teléfono disponible)
      actions: [
        if (_telefonoOtro.isNotEmpty)
          Container(
            margin: const EdgeInsets.only(right: 8),
            child: IconButton(
              icon: Icon(Icons.call_rounded, color: ConstantColors.primaryBlue),
              tooltip: 'Llamar',
              onPressed: _llamar,
            ),
          ),
      ],
    );
  }

  Widget _buildListaMensajes() {
    if (_mensajes.isEmpty) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.chat_bubble_outline_rounded,
                color: ConstantColors.textSubtle, size: 48),
            const SizedBox(height: 14),
            Text(
              'Inicia la conversación',
              style: TextStyle(
                  color: ConstantColors.textGrey,
                  fontSize: 15,
                  fontWeight: FontWeight.w600),
            ),
            const SizedBox(height: 6),
            Text(
              'Los mensajes son privados entre tú\ny ${_nombreOtro.split(' ').first}',
              style: TextStyle(color: ConstantColors.textSubtle, fontSize: 12),
              textAlign: TextAlign.center,
            ),
          ],
        ),
      );
    }

    return ListView.builder(
      controller: _scrollCtrl,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      itemCount: _mensajes.length,
      itemBuilder: (_, i) => _buildBurbuja(_mensajes[i]),
    );
  }

  Widget _buildBurbuja(ChatMessage msg) {
    final esMio = msg.remitente == _remitente;
    final color = esMio
        ? ConstantColors.primaryViolet
        : ConstantColors.backgroundCard;
    final textColor = esMio ? Colors.white : ConstantColors.textWhite;
    final borderColor = esMio
        ? ConstantColors.primaryViolet.withOpacity(0.0)
        : ConstantColors.borderColor;

    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Row(
        mainAxisAlignment:
            esMio ? MainAxisAlignment.end : MainAxisAlignment.start,
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          if (!esMio) ...[
            CircleAvatar(
              radius: 14,
              backgroundColor: ConstantColors.primaryBlue.withOpacity(0.15),
              child: Icon(
                _remitente == 'pasajero'
                    ? Icons.directions_car_rounded
                    : Icons.person_rounded,
                color: ConstantColors.primaryBlue,
                size: 15,
              ),
            ),
            const SizedBox(width: 8),
          ],
          Flexible(
            child: Container(
              constraints: BoxConstraints(
                maxWidth: MediaQuery.of(context).size.width * 0.72,
              ),
              padding:
                  const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
              decoration: BoxDecoration(
                color: color,
                borderRadius: BorderRadius.only(
                  topLeft: const Radius.circular(18),
                  topRight: const Radius.circular(18),
                  bottomLeft: Radius.circular(esMio ? 18 : 4),
                  bottomRight: Radius.circular(esMio ? 4 : 18),
                ),
                border: Border.all(color: borderColor),
                boxShadow: [
                  BoxShadow(
                    color: Colors.black.withOpacity(0.15),
                    blurRadius: 4,
                    offset: const Offset(0, 2),
                  ),
                ],
              ),
              child: Column(
                crossAxisAlignment: esMio
                    ? CrossAxisAlignment.end
                    : CrossAxisAlignment.start,
                children: [
                  Text(
                    msg.mensaje,
                    style: TextStyle(
                        color: textColor,
                        fontSize: 14,
                        height: 1.4),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    msg.horaFormateada,
                    style: TextStyle(
                      color: esMio
                          ? Colors.white60
                          : ConstantColors.textSubtle,
                      fontSize: 10,
                    ),
                  ),
                ],
              ),
            ),
          ),
          if (esMio) const SizedBox(width: 4),
        ],
      ),
    );
  }

  Widget _buildMensajesRapidos() {
    return Container(
      height: 44,
      color: ConstantColors.backgroundCard,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
        itemCount: _rapidos.length,
        separatorBuilder: (_, __) => const SizedBox(width: 8),
        itemBuilder: (_, i) {
          return GestureDetector(
            onTap: () => _enviar(_rapidos[i]),
            child: Container(
              padding:
                  const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
              decoration: BoxDecoration(
                color: ConstantColors.primaryViolet.withOpacity(0.12),
                borderRadius: BorderRadius.circular(20),
                border: Border.all(
                    color: ConstantColors.primaryViolet.withOpacity(0.35)),
              ),
              child: Text(
                _rapidos[i],
                style: TextStyle(
                  color: ConstantColors.primaryViolet,
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ),
          );
        },
      ),
    );
  }

  Widget _buildInput() {
    return Container(
      padding: EdgeInsets.fromLTRB(
          16, 10, 12, MediaQuery.of(context).padding.bottom + 12),
      decoration: BoxDecoration(
        color: ConstantColors.backgroundCard,
        border: Border(
            top: BorderSide(color: ConstantColors.borderColor)),
      ),
      child: Row(children: [
        Expanded(
          child: Container(
            decoration: BoxDecoration(
              color: ConstantColors.backgroundDark,
              borderRadius: BorderRadius.circular(24),
              border: Border.all(color: ConstantColors.borderColor),
            ),
            child: TextField(
              controller: _inputCtrl,
              style: TextStyle(color: ConstantColors.textWhite, fontSize: 14),
              maxLines: 4,
              minLines: 1,
              textCapitalization: TextCapitalization.sentences,
              decoration: InputDecoration(
                hintText: 'Escribe un mensaje...',
                hintStyle: TextStyle(
                    color: ConstantColors.textSubtle, fontSize: 14),
                border: InputBorder.none,
                contentPadding: const EdgeInsets.symmetric(
                    horizontal: 16, vertical: 10),
              ),
              onSubmitted: _enviar,
            ),
          ),
        ),
        const SizedBox(width: 8),
        GestureDetector(
          onTap: () => _enviar(_inputCtrl.text),
          child: AnimatedContainer(
            duration: const Duration(milliseconds: 200),
            width: 46,
            height: 46,
            decoration: BoxDecoration(
              gradient: LinearGradient(
                colors: [
                  ConstantColors.primaryViolet,
                  ConstantColors.primaryBlue,
                ],
              ),
              shape: BoxShape.circle,
              boxShadow: [
                BoxShadow(
                  color: ConstantColors.primaryViolet.withOpacity(0.4),
                  blurRadius: 10,
                  offset: const Offset(0, 3),
                ),
              ],
            ),
            child: _enviando
                ? const Center(
                    child: SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(
                          strokeWidth: 2,
                          valueColor:
                              AlwaysStoppedAnimation<Color>(Colors.white)),
                    ),
                  )
                : const Icon(Icons.send_rounded,
                    color: Colors.white, size: 20),
          ),
        ),
      ]),
    );
  }

  // ── Llamada directa ───────────────────────────────
  Future<void> _llamar() async {
    if (_telefonoOtro.isEmpty) return;
    final uri = Uri.parse('tel:$_telefonoOtro');
    if (await canLaunchUrl(uri)) {
      await launchUrl(uri);
    } else {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('No se pudo abrir el marcador: $_telefonoOtro'),
          backgroundColor: ConstantColors.backgroundCard,
        ),
      );
    }
  }
}
