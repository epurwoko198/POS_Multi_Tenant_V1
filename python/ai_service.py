#!/usr/bin/env python3
"""
Berkah POS - Local AI Analytics Service
=======================================
Lightweight HTTP service (standard library only) exposing forecasting,
anomaly detection, reorder recommendations, RFM, market basket and a
natural-language question endpoint.

Run:
    python python/ai_service.py
Environment:
    AI_SERVICE_PORT   default 8765
    AI_SERVICE_HOST   default 127.0.0.1
    AI_SERVICE_TOKEN  shared secret required in X-AI-Token
    AI_SHARE_TOKEN    token used to pull the dataset from PHP
    AI_DATASET_URL    default http://127.0.0.1:8080/internal/ai/dataset
"""
from __future__ import annotations

import json
import os
import sys
import time
import traceback
import urllib.error
import urllib.parse
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from threading import Lock

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from analytics import Dataset  # noqa: E402

HOST = os.environ.get("AI_SERVICE_HOST", "127.0.0.1")
PORT = int(os.environ.get("AI_SERVICE_PORT", "8765"))
TOKEN = os.environ.get("AI_SERVICE_TOKEN", "change-me")
SHARE_TOKEN = os.environ.get("AI_SHARE_TOKEN", TOKEN)
DATASET_URL = os.environ.get("AI_DATASET_URL", "http://127.0.0.1:8080/internal/ai/dataset")

_cache: dict[int, tuple[float, dict]] = {}
_cache_lock = Lock()
_CACHE_TTL = 90


def fetch_dataset(tenant_id: int) -> dict:
    now = time.time()
    with _cache_lock:
        cached = _cache.get(tenant_id)
        if cached and now - cached[0] < _CACHE_TTL:
            return cached[1]
    url = DATASET_URL + "?" + urllib.parse.urlencode({"tenant_id": tenant_id})
    request = urllib.request.Request(url, headers={"X-AI-Token": SHARE_TOKEN, "Accept": "application/json"})
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            payload = json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as exc:
        raise RuntimeError(f"Dataset HTTP {exc.code}: {exc.reason}") from exc
    except urllib.error.URLError as exc:
        raise RuntimeError(f"Tidak dapat menghubungi aplikasi PHP: {exc.reason}") from exc
    if not payload.get("ok"):
        raise RuntimeError(payload.get("message", "Dataset tidak valid"))
    with _cache_lock:
        _cache[tenant_id] = (now, payload)
    return payload


class Handler(BaseHTTPRequestHandler):
    server_version = "BerkahAI/1.0"

    def log_message(self, fmt: str, *args) -> None:  # quieter logs
        sys.stderr.write("[ai] %s - %s\n" % (self.address_string(), fmt % args))

    # ------------------------------------------------------------------ helpers
    def _json(self, status: int, payload: dict) -> None:
        body = json.dumps(payload, ensure_ascii=False, default=str).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(body)

    def _authorized(self) -> bool:
        return self.headers.get("X-AI-Token", "") == TOKEN

    def _query(self) -> dict:
        parsed = urllib.parse.urlparse(self.path)
        return {k: v[0] for k, v in urllib.parse.parse_qs(parsed.query).items()}

    def _tenant(self, query: dict, body: dict | None = None) -> int:
        raw = (body or {}).get("tenant_id") or query.get("tenant_id") or 0
        try:
            tenant_id = int(raw)
        except (TypeError, ValueError):
            tenant_id = 0
        if tenant_id <= 0:
            raise ValueError("tenant_id wajib diisi")
        return tenant_id

    def _dataset(self, tenant_id: int) -> Dataset:
        return Dataset(fetch_dataset(tenant_id))

    # --------------------------------------------------------------------- routes
    def do_GET(self) -> None:
        path = urllib.parse.urlparse(self.path).path.rstrip("/") or "/"
        try:
            if path == "/health":
                self._json(200, {"status": "ok", "service": "berkah-ai", "version": "1.0.0", "time": int(time.time())})
                return
            if not self._authorized():
                self._json(401, {"ok": False, "detail": "Unauthorized"})
                return
            query = self._query()
            tenant_id = self._tenant(query)
            ds = self._dataset(tenant_id)
            if path in ("/overview", "/"):
                self._json(200, {
                    "ok": True,
                    "summary": ds.summary(),
                    "forecast": ds.forecast(14),
                    "reorder": ds.reorder(),
                })
            elif path == "/summary":
                self._json(200, ds.summary())
            elif path == "/forecast":
                horizon = max(1, min(60, int(query.get("horizon", 14))))
                self._json(200, ds.forecast(horizon))
            elif path == "/anomalies":
                self._json(200, ds.anomalies())
            elif path == "/reorder":
                lead = max(1, min(30, int(query.get("lead_time", 7))))
                self._json(200, ds.reorder(lead_time=lead))
            elif path == "/rfm":
                self._json(200, ds.rfm())
            elif path == "/basket":
                self._json(200, ds.basket())
            elif path == "/menu":
                self._json(200, ds.menu())
            elif path == "/elasticity":
                # Price elasticity is derived from observed price changes per product.
                rows = ds.product_sales(180)
                items = []
                for row in rows[:20]:
                    items.append({
                        "name": row["name"],
                        "revenue": row["revenue"],
                        "qty": row["qty"],
                        "margin_pct": row["margin_pct"],
                        "note": "Elastisitas harga membutuhkan riwayat perubahan harga; gunakan margin & volume sebagai proksi.",
                    })
                self._json(200, {"items": items, "method": "observational_proxy"})
            else:
                self._json(404, {"ok": False, "detail": "Endpoint tidak ditemukan"})
        except Exception as exc:  # noqa: BLE001
            traceback.print_exc()
            self._json(500, {"ok": False, "detail": str(exc)})

    def do_POST(self) -> None:
        path = urllib.parse.urlparse(self.path).path.rstrip("/")
        try:
            if not self._authorized():
                self._json(401, {"ok": False, "detail": "Unauthorized"})
                return
            length = int(self.headers.get("Content-Length", "0") or 0)
            body = json.loads(self.rfile.read(length).decode("utf-8") or "{}") if length else {}
            query = self._query()
            tenant_id = self._tenant(query, body)
            # Prefer the dataset pushed by the PHP application; fall back to
            # pulling it from the shared-token endpoint when run standalone.
            dataset = body.get("dataset") if isinstance(body.get("dataset"), dict) else None
            ds = Dataset(dataset) if dataset else self._dataset(tenant_id)
            if path in ("/summary", "/forecast", "/anomalies", "/reorder", "/rfm", "/basket", "/menu", "/overview"):
                if path == "/summary":
                    result = ds.summary()
                elif path == "/forecast":
                    result = ds.forecast(max(1, min(60, int(body.get("horizon", 14)))))
                elif path == "/anomalies":
                    result = ds.anomalies()
                elif path == "/reorder":
                    result = ds.reorder(lead_time=max(1, min(30, int(body.get("lead_time", 7)))))
                elif path == "/rfm":
                    result = ds.rfm()
                elif path == "/basket":
                    result = ds.basket()
                elif path == "/menu":
                    result = ds.menu()
                else:
                    result = {"summary": ds.summary(), "forecast": ds.forecast(14), "reorder": ds.reorder()}
                self._json(200, result)
                return
            if path == "/ask":
                question = str(body.get("question", "")).strip()
                if not question:
                    self._json(422, {"ok": False, "detail": "Pertanyaan kosong"})
                    return
                self._json(200, ds.ask(question))
            elif path == "/invalidate":
                with _cache_lock:
                    _cache.pop(tenant_id, None)
                self._json(200, {"ok": True, "message": "Cache dataset dihapus"})
            else:
                self._json(404, {"ok": False, "detail": "Endpoint tidak ditemukan"})
        except Exception as exc:  # noqa: BLE001
            traceback.print_exc()
            self._json(500, {"ok": False, "detail": str(exc)})


def main() -> None:
    server = ThreadingHTTPServer((HOST, PORT), Handler)
    server.daemon_threads = True
    print(f"Berkah AI service listening on http://{HOST}:{PORT}")
    print(f"Dataset source: {DATASET_URL}")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nStopping AI service.")
        server.shutdown()


if __name__ == "__main__":
    main()
