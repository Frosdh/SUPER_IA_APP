import os
import re

def fix_file(path):
    print(f"Fixing {path}...")
    with open(path, 'r', encoding='utf-8') as f:
        content = f.read()
    
    # UPDATE string
    pattern1 = r"'dddssdddsiiiiiidddddddddddddddddddddddsssssddddddddddiiiiissssdddddddddisiss\s+ss\s+s'"
    replacement1 = "'dddssdddsiiiiiidddddddddddddddddddddddsssssddddddddddiiiiissssdddddddddisisssss'"
    
    # INSERT string (if not already fixed)
    pattern2 = r"'ssdddssdddsiiiiiidddddddddddddddddddddddsssssddddddddddiiiiissssdddddddddisiss\s+ss\s+'"
    replacement2 = "'ssdddssdddsiiiiiidddddddddddddddddddddddsssssddddddddddiiiiissssdddddddddisissss'"
    
    new_content = re.sub(pattern1, replacement1, content)
    new_content = re.sub(pattern2, replacement2, new_content)
    
    if new_content != content:
        with open(path, 'w', encoding='utf-8') as f:
            f.write(new_content)
        print("Fixed!")
    else:
        print("No changes needed or pattern not found.")

fix_file(r'c:\xampp\htdocs\SUPER_IA\server_php\actualizar_encuesta_completa.php')
fix_file(r'c:\xampp\htdocs\SUPER_IA\server_php\guardar_cliente_encuesta.php')
