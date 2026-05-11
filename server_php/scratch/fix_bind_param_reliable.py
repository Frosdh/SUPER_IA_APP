import os

def fix_file_at_line(path, line_idx, new_content):
    with open(path, 'r', encoding='utf-8') as f:
        lines = f.readlines()
    lines[line_idx] = new_content
    with open(path, 'w', encoding='utf-8') as f:
        f.write(''.join(lines))

# actualizar_encuesta_completa.php
fix_file_at_line(r'c:\xampp\htdocs\SUPER_IA\server_php\actualizar_encuesta_completa.php', 745, "                'dddssdddsiiiiiidddddddddddddddddddddddsssssddddddddddiiiiissssdddddddddisisssss',\n")
fix_file_at_line(r'c:\xampp\htdocs\SUPER_IA\server_php\actualizar_encuesta_completa.php', 794, "                'ssdddssdddsiiiiiidddddddddddddddddddddddsssssddddddddddiiiiissssdddddddddisissss',\n")

# guardar_cliente_encuesta.php
fix_file_at_line(r'c:\xampp\htdocs\SUPER_IA\server_php\guardar_cliente_encuesta.php', 913, "                    'dddssdddsiiiiiidddddddddddddddddddddddsssssddddddddddiiiiissssdddddddddisisssss',\n")
fix_file_at_line(r'c:\xampp\htdocs\SUPER_IA\server_php\guardar_cliente_encuesta.php', 957, "                    'ssdddssdddsiiiiiidddddddddddddddddddddddsssssddddddddddiiiiissssdddddddddisissss',\n")

print("All fixed!")
