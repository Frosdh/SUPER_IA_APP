import 'package:flutter/material.dart';
import 'package:fu_uber/Core/Constants/colorConstants.dart';
import 'package:fu_uber/Core/Preferences/EmergencyContactsService.dart';

class EmergencyContactsScreen extends StatefulWidget {
  static const String route = '/emergency_contacts';

  @override
  _EmergencyContactsScreenState createState() => _EmergencyContactsScreenState();
}

class _EmergencyContactsScreenState extends State<EmergencyContactsScreen> {
  List<ContactoEmergencia> _contactos = [];
  bool _cargando = true;

  @override
  void initState() {
    super.initState();
    _cargarContactos();
  }

  Future<void> _cargarContactos() async {
    final contactos = await EmergencyContactsService.obtenerContactos();
    if (mounted) {
      setState(() {
        _contactos = contactos;
        _cargando = false;
      });
    }
  }

  void _abrirDialogoAgregarContacto({ContactoEmergencia? contactoExistente}) {
    final esEditar = contactoExistente != null;
    final nombreController = TextEditingController(text: esEditar ? contactoExistente.nombre : '');
    final telefonoController = TextEditingController(text: esEditar ? contactoExistente.telefono : '');

    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: ConstantColors.backgroundCard,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: Text(
          esEditar ? 'Editar contacto' : 'Agregar contacto',
          style: TextStyle(color: ConstantColors.textWhite, fontWeight: FontWeight.w700),
        ),
        content: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                controller: nombreController,
                style: TextStyle(color: ConstantColors.textWhite),
                decoration: InputDecoration(
                  hintText: 'Nombre (ej: Mamá)',
                  hintStyle: TextStyle(color: ConstantColors.textSubtle),
                  filled: true,
                  fillColor: ConstantColors.backgroundDark,
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: BorderSide(color: ConstantColors.borderColor),
                  ),
                ),
              ),
              SizedBox(height: 12),
              TextField(
                controller: telefonoController,
                style: TextStyle(color: ConstantColors.textWhite),
                keyboardType: TextInputType.phone,
                decoration: InputDecoration(
                  hintText: 'Teléfono (ej: +593 9 12345678)',
                  hintStyle: TextStyle(color: ConstantColors.textSubtle),
                  filled: true,
                  fillColor: ConstantColors.backgroundDark,
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: BorderSide(color: ConstantColors.borderColor),
                  ),
                ),
              ),
            ],
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: Text('Cancelar', style: TextStyle(color: ConstantColors.textGrey)),
          ),
          ElevatedButton(
            onPressed: () async {
              if (nombreController.text.isEmpty || telefonoController.text.isEmpty) {
                showDialog(
                  context: ctx,
                  builder: (_) => AlertDialog(
                    backgroundColor: ConstantColors.backgroundCard,
                    title: Text('Campo incompleto', style: TextStyle(color: ConstantColors.textWhite)),
                    content: Text('Por favor completa el nombre y teléfono', style: TextStyle(color: ConstantColors.textGrey)),
                    actions: [
                      TextButton(
                        onPressed: () => Navigator.pop(_),
                        child: Text('OK', style: TextStyle(color: ConstantColors.primaryViolet)),
                      ),
                    ],
                  ),
                );
                return;
              }

              final nuevoContacto = ContactoEmergencia(
                nombre: nombreController.text.trim(),
                telefono: telefonoController.text.trim(),
              );

              if (esEditar) {
                await EmergencyContactsService.actualizarContacto(contactoExistente.telefono, nuevoContacto);
              } else {
                await EmergencyContactsService.agregarContacto(nuevoContacto);
              }

              await _cargarContactos();
              Navigator.pop(ctx);
            },
            style: ElevatedButton.styleFrom(
              backgroundColor: ConstantColors.primaryViolet,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
            ),
            child: Text(esEditar ? 'Actualizar' : 'Agregar', style: TextStyle(color: Colors.white)),
          ),
        ],
      ),
    );
  }

  void _eliminarContacto(String telefono) async {
    await EmergencyContactsService.eliminarContacto(telefono);
    await _cargarContactos();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: ConstantColors.backgroundDark,
      appBar: AppBar(
        backgroundColor: ConstantColors.backgroundCard,
        elevation: 0,
        title: Text(
          'Contactos de Emergencia',
          style: TextStyle(color: ConstantColors.textWhite, fontWeight: FontWeight.w700),
        ),
        leading: IconButton(
          icon: Icon(Icons.arrow_back, color: ConstantColors.primaryViolet),
          onPressed: () => Navigator.pop(context),
        ),
      ),
      body: _cargando
          ? Center(child: CircularProgressIndicator(valueColor: AlwaysStoppedAnimation(ConstantColors.primaryViolet)))
          : _contactos.isEmpty
              ? Center(
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Icon(Icons.warning, size: 64, color: ConstantColors.textGrey),
                      SizedBox(height: 16),
                      Text(
                        'Sin contactos registrados',
                        style: TextStyle(color: ConstantColors.textWhite, fontSize: 16, fontWeight: FontWeight.w600),
                      ),
                      SizedBox(height: 8),
                      Text(
                        'Agrega contactos de emergencia para usarlos en caso de necesidad',
                        style: TextStyle(color: ConstantColors.textGrey, fontSize: 13),
                        textAlign: TextAlign.center,
                      ),
                    ],
                  ),
                )
              : ListView.builder(
                  padding: EdgeInsets.all(16),
                  itemCount: _contactos.length,
                  itemBuilder: (_, i) {
                    final contacto = _contactos[i];
                    return Container(
                      margin: EdgeInsets.only(bottom: 12),
                      padding: EdgeInsets.all(16),
                      decoration: BoxDecoration(
                        color: ConstantColors.backgroundCard,
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(color: ConstantColors.borderColor),
                      ),
                      child: Row(
                        children: [
                          CircleAvatar(
                            radius: 28,
                            backgroundColor: ConstantColors.primaryViolet.withOpacity(0.2),
                            child: Icon(Icons.warning, color: ConstantColors.primaryViolet, size: 24),
                          ),
                          SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  contacto.nombre,
                                  style: TextStyle(color: ConstantColors.textWhite, fontSize: 14, fontWeight: FontWeight.w700),
                                ),
                                SizedBox(height: 4),
                                Text(
                                  contacto.telefono,
                                  style: TextStyle(color: ConstantColors.textGrey, fontSize: 12),
                                ),
                              ],
                            ),
                          ),
                          IconButton(
                            icon: Icon(Icons.edit_outlined, color: ConstantColors.primaryViolet, size: 20),
                            onPressed: () => _abrirDialogoAgregarContacto(contactoExistente: contacto),
                          ),
                          IconButton(
                            icon: Icon(Icons.delete_outline, color: Colors.red, size: 20),
                            onPressed: () => _eliminarContacto(contacto.telefono),
                          ),
                        ],
                      ),
                    );
                  },
                ),
      floatingActionButton: FloatingActionButton(
        onPressed: () => _abrirDialogoAgregarContacto(),
        backgroundColor: ConstantColors.primaryViolet,
        child: Icon(Icons.add, color: Colors.white),
      ),
    );
  }
}
