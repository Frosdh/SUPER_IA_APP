import 'package:flutter/material.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';
import 'package:fu_uber/Core/Preferences/FavoritePlacesService.dart';
import 'package:fu_uber/Core/Services/OsmService.dart';

class FavoritePlacesScreen extends StatefulWidget {
  static const String route = '/favorite_places';

  @override
  _FavoritePlacesScreenState createState() => _FavoritePlacesScreenState();
}

class _FavoritePlacesScreenState extends State<FavoritePlacesScreen> {
  List<LugarFavorito> _favoritos = [];
  bool _cargando = true;

  @override
  void initState() {
    super.initState();
    _cargarFavoritos();
  }

  Future<void> _cargarFavoritos() async {
    final lista = await FavoritePlacesService.obtenerFavoritos();
    if (mounted) setState(() { _favoritos = lista; _cargando = false; });
  }

  Future<void> _agregarEditar(String tipo) async {
    final existente = _favoritos.where((l) => l.tipo == tipo).firstOrNull;

    final TextEditingController controller = TextEditingController(
      text: existente?.direccion ?? '',
    );

    String nombreTipo = tipo == 'casa' ? 'Casa' : 'Trabajo';
    String iconoTipo  = tipo == 'casa' ? '🏠' : '💼';

    List<PlaceResult> sugerencias = [];
    bool buscando = false;

    await showModalBottomSheet(
      context: context,
      backgroundColor: ConstantColors.backgroundCard,
      isScrollControlled: true,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (ctx) {
        return StatefulBuilder(builder: (ctx2, setModalState) {
          return Padding(
            padding: EdgeInsets.only(
              left: 24, right: 24, top: 16,
              bottom: MediaQuery.of(ctx2).viewInsets.bottom + 24,
            ),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // Handle
                Center(
                  child: Container(
                    width: 40, height: 4,
                    decoration: BoxDecoration(
                      color: Colors.white24,
                      borderRadius: BorderRadius.circular(4),
                    ),
                  ),
                ),
                SizedBox(height: 20),
                Text(
                  '$iconoTipo  Dirección de $nombreTipo',
                  style: TextStyle(
                    color: ConstantColors.textWhite,
                    fontSize: 17,
                    fontWeight: FontWeight.w700,
                  ),
                ),
                SizedBox(height: 16),

                // Campo de búsqueda
                TextField(
                  controller: controller,
                  autofocus: true,
                  style: TextStyle(color: ConstantColors.textWhite),
                  decoration: InputDecoration(
                    hintText: 'Busca la dirección...',
                    hintStyle: TextStyle(color: ConstantColors.textSubtle),
                    filled: true,
                    fillColor: ConstantColors.backgroundDark,
                    prefixIcon: Icon(Icons.search, color: ConstantColors.primaryViolet),
                    suffixIcon: buscando
                        ? Padding(
                            padding: EdgeInsets.all(12),
                            child: SizedBox(
                              width: 18, height: 18,
                              child: CircularProgressIndicator(
                                strokeWidth: 2,
                                valueColor: AlwaysStoppedAnimation<Color>(
                                    ConstantColors.primaryViolet),
                              ),
                            ),
                          )
                        : null,
                    border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(14),
                      borderSide: BorderSide(color: ConstantColors.borderColor),
                    ),
                    enabledBorder: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(14),
                      borderSide: BorderSide(color: ConstantColors.borderColor),
                    ),
                    focusedBorder: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(14),
                      borderSide: BorderSide(color: ConstantColors.primaryViolet),
                    ),
                  ),
                  onChanged: (valor) async {
                    if (valor.length < 3) {
                      try { setModalState(() => sugerencias = []); } catch (_) {}
                      return;
                    }
                    try { setModalState(() => buscando = true); } catch (_) {}
                    final resultados = await OsmService.buscarLugar(valor);
                    // El modal puede haberse cerrado mientras esperaba la respuesta
                    try {
                      setModalState(() {
                        sugerencias = resultados;
                        buscando   = false;
                      });
                    } catch (_) {}
                  },
                ),

                // Sugerencias
                if (sugerencias.isNotEmpty) ...[
                  SizedBox(height: 8),
                  Container(
                    constraints: BoxConstraints(maxHeight: 200),
                    decoration: BoxDecoration(
                      color: ConstantColors.backgroundDark,
                      borderRadius: BorderRadius.circular(14),
                      border: Border.all(color: ConstantColors.borderColor),
                    ),
                    child: ListView.separated(
                      shrinkWrap: true,
                      padding: EdgeInsets.symmetric(vertical: 6),
                      itemCount: sugerencias.length,
                      separatorBuilder: (_, __) =>
                          Divider(height: 1, color: ConstantColors.dividerColor),
                      itemBuilder: (_, i) {
                        final s = sugerencias[i];
                        return ListTile(
                          dense: true,
                          leading: Icon(Icons.location_on,
                              color: ConstantColors.primaryViolet, size: 18),
                          title: Text(
                            s.shortName,
                            style: TextStyle(
                              color: ConstantColors.textWhite,
                              fontSize: 13,
                            ),
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                          ),
                          onTap: () async {
                            // Guardar favorito
                            final lugar = LugarFavorito(
                              tipo:      tipo,
                              nombre:    nombreTipo,
                              direccion: s.shortName,
                              lat:       s.lat,
                              lng:       s.lon,
                            );
                            await FavoritePlacesService.guardarFavorito(lugar);
                            Navigator.pop(ctx2);
                            _cargarFavoritos();
                          },
                        );
                      },
                    ),
                  ),
                ],

                SizedBox(height: 12),

                // Botón eliminar (si ya existe)
                if (existente != null)
                  GestureDetector(
                    onTap: () async {
                      await FavoritePlacesService.eliminarFavorito(tipo);
                      Navigator.pop(ctx2);
                      _cargarFavoritos();
                    },
                    child: Container(
                      width: double.infinity,
                      padding: EdgeInsets.symmetric(vertical: 13),
                      decoration: BoxDecoration(
                        color: Colors.red.withOpacity(0.1),
                        borderRadius: BorderRadius.circular(14),
                        border: Border.all(color: Colors.red.withOpacity(0.3)),
                      ),
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Icon(Icons.delete_outline, color: Colors.redAccent, size: 18),
                          SizedBox(width: 8),
                          Text(
                            'Eliminar $nombreTipo',
                            style: TextStyle(
                              color: Colors.redAccent,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
              ],
            ),
          );
        });
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    final LugarFavorito? casa = _favoritos.where((l) => l.tipo == 'casa').firstOrNull;
    final LugarFavorito? trabajo = _favoritos.where((l) => l.tipo == 'trabajo').firstOrNull;

    return Scaffold(
      backgroundColor: ConstantColors.backgroundDark,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        elevation: 0,
        leading: IconButton(
          icon: Icon(Icons.arrow_back, color: Colors.white),
          onPressed: () => Navigator.pop(context),
        ),
        title: Text(
          'Lugares favoritos',
          style: TextStyle(color: Colors.white, fontWeight: FontWeight.w700),
        ),
      ),
      body: _cargando
          ? Center(child: CircularProgressIndicator(
              valueColor: AlwaysStoppedAnimation<Color>(ConstantColors.primaryViolet),
            ))
          : Padding(
              padding: EdgeInsets.all(20),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Guarda tus lugares frecuentes para pedir viajes más rápido.',
                    style: TextStyle(color: ConstantColors.textGrey, fontSize: 13),
                  ),
                  SizedBox(height: 24),

                  // Tarjeta Casa
                  _favoritoCard(
                    tipo:    'casa',
                    icono:   '🏠',
                    titulo:  'Casa',
                    lugar:   casa,
                    color:   Color(0xFF60A5FA),
                  ),
                  SizedBox(height: 14),

                  // Tarjeta Trabajo
                  _favoritoCard(
                    tipo:    'trabajo',
                    icono:   '💼',
                    titulo:  'Trabajo',
                    lugar:   trabajo,
                    color:   Color(0xFFA78BFA),
                  ),

                  SizedBox(height: 30),

                  // Tip
                  Container(
                    padding: EdgeInsets.all(16),
                    decoration: BoxDecoration(
                      color: ConstantColors.primaryViolet.withOpacity(0.08),
                      borderRadius: BorderRadius.circular(14),
                      border: Border.all(
                        color: ConstantColors.primaryViolet.withOpacity(0.2),
                      ),
                    ),
                    child: Row(
                      children: [
                        Icon(Icons.lightbulb_outline,
                            color: ConstantColors.primaryViolet, size: 20),
                        SizedBox(width: 12),
                        Expanded(
                          child: Text(
                            'En el mapa verás los botones 🏠 Casa y 💼 Trabajo para pedir tu viaje con un solo toque.',
                            style: TextStyle(
                              color: ConstantColors.textGrey,
                              fontSize: 12,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
    );
  }

  Widget _favoritoCard({
    required String tipo,
    required String icono,
    required String titulo,
    required LugarFavorito? lugar,
    required Color color,
  }) {
    final bool tieneDir = lugar != null;
    return GestureDetector(
      onTap: () => _agregarEditar(tipo),
      child: Container(
        width: double.infinity,
        padding: EdgeInsets.all(18),
        decoration: BoxDecoration(
          color: ConstantColors.backgroundCard,
          borderRadius: BorderRadius.circular(18),
          border: Border.all(
            color: tieneDir ? color.withOpacity(0.4) : ConstantColors.borderColor,
            width: tieneDir ? 1.5 : 1,
          ),
        ),
        child: Row(
          children: [
            Container(
              width: 48,
              height: 48,
              decoration: BoxDecoration(
                color: color.withOpacity(0.12),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Center(
                child: Text(icono, style: TextStyle(fontSize: 24)),
              ),
            ),
            SizedBox(width: 16),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    titulo,
                    style: TextStyle(
                      color: ConstantColors.textWhite,
                      fontSize: 15,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                  SizedBox(height: 3),
                  Text(
                    tieneDir ? lugar.direccion : 'Toca para agregar dirección',
                    style: TextStyle(
                      color: tieneDir ? ConstantColors.textGrey : ConstantColors.textSubtle,
                      fontSize: 12,
                    ),
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
                ],
              ),
            ),
            Icon(
              tieneDir ? Icons.edit : Icons.add,
              color: tieneDir ? color : ConstantColors.textSubtle,
              size: 20,
            ),
          ],
        ),
      ),
    );
  }
}
