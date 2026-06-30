"""
importar_seps.py
================
Descarga el catastro oficial de entidades activas de la SEPS y lo importa
directamente en la tabla `seps_cooperativas` de MySQL.

Requisitos:
    pip install playwright pandas openpyxl mysql-connector-python

Primer uso:
    python -m playwright install chromium

Ejecutar:
    python importar_seps.py
"""

import asyncio
import os
import unicodedata
import pandas as pd
import mysql.connector
from playwright.async_api import async_playwright

# ─────────────────────────────────────────────
# CONFIGURACIÓN — ajusta si cambian tus datos
# ─────────────────────────────────────────────
DB_CONFIG = {
    "host":     "localhost",
    "database": "corporat_base_super_ia",
    "user":     "corporat_coac_user",
    "password": "*6LuhePgy=9?Zy-&",
    "charset":  "utf8mb4",
}

SEPS_URL   = "https://servicios.seps.gob.ec/gosf-internet/paginas/consultarOrganizaciones.jsf"
EXCEL_PATH = "./catastro_seps_activas.xlsx"
CSV_PATH   = "./catastro_seps_activas.csv"


# ─────────────────────────────────────────────
# PASO 1 — Descargar Excel desde SEPS
# ─────────────────────────────────────────────
async def descargar_catastro():
    print("🌐 Abriendo portal SEPS...")
    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=False)
        page    = await browser.new_page()

        await page.goto(SEPS_URL, timeout=60_000)
        await page.wait_for_load_state("networkidle")

        print("📥 Haciendo clic en 'Descarga catastro entidades activas'...")
        async with page.expect_download(timeout=90_000) as dl_info:
            await page.get_by_text("Descarga catastro entidades activas").click()

        dl = await dl_info.value
        await dl.save_as(EXCEL_PATH)
        print(f"✅ Descargado → {EXCEL_PATH}")
        await browser.close()


# ─────────────────────────────────────────────
# PASO 2 — Procesar Excel con Pandas
# ─────────────────────────────────────────────
def _norm(txt):
    """Quita tildes y pasa a minúsculas."""
    if not isinstance(txt, str):
        return ""
    return "".join(
        c for c in unicodedata.normalize("NFD", txt)
        if unicodedata.category(c) != "Mn"
    ).lower()

COLUMNAS_REQUERIDAS = {
    # nombre_columna_csv    →  campo_tabla_mysql
    "ruc":                  "ruc",
    "razon social":         "razon_social",
    "nombre comercial":     "nombre_comercial",
    "tipo de organizacion": "tipo_organizacion",
    "segmento":             "segmento",
    "estado":               "estado",
    "provincia":            "provincia",
    "canton":               "canton",
    "parroquia":            "parroquia",
    "direccion":            "direccion",
    "telefono":             "telefono",
    "correo electronico":   "correo",
    "representante legal":  "representante_legal",
    "fecha de constitucion":"fecha_constitucion",
}

def procesar_excel():
    print("📊 Procesando Excel...")
    df = pd.read_excel(EXCEL_PATH, skiprows=2)
    df = df.dropna(how="all")

    # Normalizar nombres de columnas (sin tildes, minúsculas)
    df.columns = [_norm(str(c)) for c in df.columns]

    # Guardar CSV de respaldo
    df.to_csv(CSV_PATH, index=False, encoding="utf-8")
    print(f"💾 CSV guardado → {CSV_PATH}  ({len(df)} filas)")

    return df


# ─────────────────────────────────────────────
# PASO 3 — Importar a MySQL
# ─────────────────────────────────────────────
def importar_mysql(df: pd.DataFrame):
    print("🗄️  Conectando a MySQL...")
    cn = mysql.connector.connect(**DB_CONFIG)
    cur = cn.cursor()

    # Vaciar tabla y reinsertar (UPSERT por RUC)
    insertados = 0
    actualizados = 0
    errores = 0

    sql_upsert = """
        INSERT INTO seps_cooperativas
            (ruc, razon_social, nombre_comercial, tipo_organizacion, segmento,
             estado, provincia, canton, parroquia, direccion, telefono,
             correo, representante_legal, fecha_constitucion, activo, importado_at)
        VALUES
            (%(ruc)s, %(razon_social)s, %(nombre_comercial)s, %(tipo_organizacion)s,
             %(segmento)s, %(estado)s, %(provincia)s, %(canton)s, %(parroquia)s,
             %(direccion)s, %(telefono)s, %(correo)s, %(representante_legal)s,
             %(fecha_constitucion)s, 1, NOW())
        ON DUPLICATE KEY UPDATE
            razon_social      = VALUES(razon_social),
            nombre_comercial  = VALUES(nombre_comercial),
            tipo_organizacion = VALUES(tipo_organizacion),
            segmento          = VALUES(segmento),
            estado            = VALUES(estado),
            provincia         = VALUES(provincia),
            canton            = VALUES(canton),
            parroquia         = VALUES(parroquia),
            direccion         = VALUES(direccion),
            telefono          = VALUES(telefono),
            correo            = VALUES(correo),
            representante_legal = VALUES(representante_legal),
            fecha_constitucion  = VALUES(fecha_constitucion),
            activo            = 1,
            updated_at        = NOW()
    """

    # Mapear columnas del CSV a los campos del INSERT
    def col(row, *keys):
        """Devuelve el primer valor no-vacío encontrado entre las claves dadas."""
        for k in keys:
            v = row.get(k, "")
            if pd.notna(v) and str(v).strip():
                return str(v).strip()
        return None

    def fecha(val):
        if pd.isna(val) or not str(val).strip():
            return None
        try:
            return pd.to_datetime(val).date().isoformat()
        except Exception:
            return None

    print(f"⬆️  Importando {len(df)} registros...")
    for _, row in df.iterrows():
        try:
            params = {
                "ruc":               col(row, "ruc"),
                "razon_social":      col(row, "razon social", "razon_social") or "SIN NOMBRE",
                "nombre_comercial":  col(row, "nombre comercial", "nombre_comercial"),
                "tipo_organizacion": col(row, "tipo de organizacion", "tipo_organizacion",
                                         "tipo organizacion"),
                "segmento":          col(row, "segmento"),
                "estado":            col(row, "estado"),
                "provincia":         col(row, "provincia"),
                "canton":            col(row, "canton", "cantón"),
                "parroquia":         col(row, "parroquia"),
                "direccion":         col(row, "direccion", "dirección"),
                "telefono":          col(row, "telefono", "teléfono"),
                "correo":            col(row, "correo electronico", "correo electrónico",
                                         "correo"),
                "representante_legal": col(row, "representante legal",
                                           "representante_legal"),
                "fecha_constitucion":  fecha(row.get("fecha de constitucion")
                                             or row.get("fecha_constitucion")),
            }
            cur.execute(sql_upsert, params)
            if cur.rowcount == 1:
                insertados += 1
            else:
                actualizados += 1
        except Exception as e:
            errores += 1
            if errores <= 5:
                print(f"   ⚠️  Fila con error: {e}")

    cn.commit()
    cur.close()
    cn.close()

    print("─" * 50)
    print(f"✅ Insertados : {insertados}")
    print(f"🔄 Actualizados: {actualizados}")
    print(f"❌ Errores    : {errores}")
    print("─" * 50)
    print("🎉 ¡Importación completada! Ahora la API devuelve los datos reales.")


# ─────────────────────────────────────────────
# MAIN
# ─────────────────────────────────────────────
async def main():
    # Si ya existe el Excel (descarga previa), reusar
    if os.path.exists(EXCEL_PATH):
        resp = input(f"⚠️  Ya existe '{EXCEL_PATH}'. ¿Re-descargar desde SEPS? [s/N]: ").strip().lower()
        if resp == "s":
            await descargar_catastro()
    else:
        await descargar_catastro()

    df = procesar_excel()
    importar_mysql(df)


if __name__ == "__main__":
    asyncio.run(main())
