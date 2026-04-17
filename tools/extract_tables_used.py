import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1] / "server_php"

# Busca tablas en fragmentos que parecen SQL (SELECT/INSERT/UPDATE/DELETE) y captura FROM/JOIN/INTO/UPDATE
SQL_BLOCK = re.compile(
    r"\b(SELECT|INSERT|UPDATE|DELETE)\b[\s\S]{0,2000}?;",
    re.IGNORECASE,
)

TABLE_TOKEN = re.compile(
    r"\b(?:FROM|JOIN|INTO|UPDATE)\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?",
    re.IGNORECASE,
)

IGNORE = {"information_schema", "mysql", "performance_schema", "sys"}


def extract_tables_from_text(text: str) -> set[str]:
    tables: set[str] = set()
    for block in SQL_BLOCK.finditer(text):
        sql = block.group(0)
        for m in TABLE_TOKEN.finditer(sql):
            t = m.group(1)
            if not t:
                continue
            tl = t.lower()
            if tl in IGNORE:
                continue
            tables.add(t)
    return tables


def main() -> None:
    tables: set[str] = set()
    php_files = list(ROOT.rglob("*.php"))
    for p in php_files:
        text = p.read_text(encoding="utf-8", errors="ignore")
        tables |= extract_tables_from_text(text)

    print(f"php_files: {len(php_files)}")
    print(f"tables_used: {len(tables)}")
    for t in sorted(tables, key=str.lower):
        print(t)


if __name__ == "__main__":
    main()
