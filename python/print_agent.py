#!/usr/bin/env python3
"""
Berkah POS - Local Print Agent
=============================
Polls the central server for queued receipt jobs and prints them to a local
thermal printer (ESC/POS). Runs on the cashier PC, makes only outbound HTTPS
requests, and never exposes an inbound port.

Run:
    python python/print_agent.py
Environment:
    POS_BASE_URL   e.g. https://pos.example.com
    DEVICE_KEY     register device key (Settings / seeded registers)
    PRINTER_NAME   Windows printer name (default printer when empty)
    ESCPOS_HOST    optional network printer IP (port 9100)
    POLL_SECONDS   default 5
    OUTPUT_DIR     fallback directory for raw jobs (default ./printed)
"""
from __future__ import annotations

import json
import os
import sys
import time
import urllib.error
import urllib.request
from datetime import datetime
from pathlib import Path

BASE_URL = os.environ.get("POS_BASE_URL", "http://127.0.0.1:8080").rstrip("/")
DEVICE_KEY = os.environ.get("DEVICE_KEY", "")
PRINTER_NAME = os.environ.get("PRINTER_NAME", "")
ESCPOS_HOST = os.environ.get("ESCPOS_HOST", "")
POLL_SECONDS = float(os.environ.get("POLL_SECONDS", "5"))
OUTPUT_DIR = Path(os.environ.get("OUTPUT_DIR", "printed"))


def api(path: str, method: str = "GET", payload: dict | None = None) -> dict:
    data = json.dumps(payload).encode() if payload is not None else None
    request = urllib.request.Request(
        BASE_URL + path,
        data=data,
        method=method,
        headers={
            "X-Device-Key": DEVICE_KEY,
            "Content-Type": "application/json",
            "Accept": "application/json",
        },
    )
    with urllib.request.urlopen(request, timeout=20) as response:
        return json.loads(response.read().decode("utf-8"))


def rupiah(value: float) -> str:
    return f"{round(float(value)):,}".replace(",", ".")


def receipt_text(payload: dict) -> str:
    order = payload.get("order", {})
    tenant = payload.get("tenant", {})
    items = payload.get("items", [])
    payments = payload.get("payments", [])
    width = 42
    lines = []
    lines.append(str(tenant.get("name", "")).upper().center(width))
    lines.append(str(order.get("outlet_name", "")).center(width))
    lines.append(str(order.get("outlet_address", "")).center(width))
    lines.append(("-" * width))
    lines.append(f"No    : {order.get('order_no', '')}")
    lines.append(f"Tgl   : {order.get('created_at', '')}")
    lines.append(f"Kasir : {order.get('cashier_name', '')}")
    lines.append("-" * width)
    for item in items:
        lines.append(str(item.get("name_snapshot", ""))[:width])
        qty = float(item.get("qty", 0))
        price = float(item.get("unit_price", 0))
        total = float(item.get("line_total", 0))
        lines.append(f"  {qty:g} x {rupiah(price):>10} {rupiah(total):>14}")
    lines.append("-" * width)
    lines.append(f"{'Subtotal':<28}{rupiah(order.get('subtotal', 0)):>14}")
    if float(order.get("discount_total", 0)) > 0:
        lines.append(f"{'Diskon':<28}{'-' + rupiah(order.get('discount_total', 0)):>14}")
    if float(order.get("service_charge", 0)) > 0:
        lines.append(f"{'Service':<28}{rupiah(order.get('service_charge', 0)):>14}")
    lines.append(f"{'Pajak':<28}{rupiah(order.get('tax_total', 0)):>14}")
    lines.append(f"{'TOTAL':<28}{rupiah(order.get('grand_total', 0)):>14}")
    for payment in payments:
        lines.append(f"{str(payment.get('method', '')).upper():<28}{rupiah(payment.get('amount', 0)):>14}")
        if float(payment.get("change_due", 0)) > 0:
            lines.append(f"{'Kembalian':<28}{rupiah(payment.get('change_due', 0)):>14}")
    lines.append("-" * width)
    lines.append(str(order.get("receipt_footer", "")))
    lines.append("Terima kasih")
    lines.append("")
    return "\n".join(lines)


def escpos_bytes(text: str) -> bytes:
    # ESC @ init, text, feed, partial cut.
    return b"\x1b@" + text.encode("cp437", errors="replace") + b"\n\n\n" + b"\x1dV\x00"


def print_windows_raw(text: str) -> bool:
    try:
        import win32print  # type: ignore
    except ImportError:
        return False
    printer = PRINTER_NAME or win32print.GetDefaultPrinter()
    handle = win32print.OpenPrinter(printer)
    try:
        win32print.StartDocPrinter(handle, 1, ("Berkah POS Receipt", None, "RAW"))
        win32print.StartPagePrinter(handle)
        win32print.WritePrinter(handle, escpos_bytes(text))
        win32print.EndPagePrinter(handle)
        win32print.EndDocPrinter(handle)
    finally:
        win32print.ClosePrinter(handle)
    return True


def print_network(text: str) -> bool:
    if not ESCPOS_HOST:
        return False
    import socket

    with socket.create_connection((ESCPOS_HOST, 9100), timeout=8) as sock:
        sock.sendall(escpos_bytes(text))
    return True


def deliver(text: str, job: dict) -> bool:
    try:
        if print_windows_raw(text) or print_network(text):
            print(f"[print] job {job.get('public_id')} -> printer")
            return True
    except Exception as exc:  # noqa: BLE001
        print(f"[print] printer error: {exc}")
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    path = OUTPUT_DIR / f"{job.get('public_id', 'job')}.txt"
    path.write_text(text, encoding="utf-8")
    raw = OUTPUT_DIR / f"{job.get('public_id', 'job')}.escpos"
    raw.write_bytes(escpos_bytes(text))
    print(f"[print] no printer available; saved {path}")
    return True


def loop() -> None:
    if not DEVICE_KEY:
        print("DEVICE_KEY belum diatur. Ambil dari tabel registers.device_key.")
        sys.exit(1)
    print(f"Berkah print agent connected to {BASE_URL}")
    print(f"Polling every {POLL_SECONDS}s. Printer: {PRINTER_NAME or '(default)'}")
    while True:
        try:
            response = api("/api/v1/print-jobs")
            for job in response.get("jobs", []):
                payload = job.get("payload") or {}
                text = receipt_text(payload)
                deliver(text, job)
                api(f"/api/v1/print-jobs/{job['public_id']}/ack", "POST", {"status": "printed"})
            if not response.get("jobs"):
                time.sleep(POLL_SECONDS)
        except urllib.error.HTTPError as exc:
            print(f"[print] HTTP {exc.code}: {exc.reason}")
            time.sleep(max(5, POLL_SECONDS))
        except KeyboardInterrupt:
            print("Stopping print agent.")
            return
        except Exception as exc:  # noqa: BLE001
            print(f"[print] {exc}")
            time.sleep(max(5, POLL_SECONDS))


if __name__ == "__main__":
    loop()
