"""
Berkah POS - AI Analytics Engine
================================
Dependency-free statistical engine (Python 3.10+). Designed for a local,
privacy-preserving deployment: the service pulls a tenant dataset from the
PHP application through a shared-token endpoint and computes everything
in-memory. No external AI API, no data leaves the server.

Models
------
* Forecast : model selection by backtest (sMAPE) among naive, seasonal naive,
             moving average, Holt linear trend and Holt-Winters additive.
* Anomaly  : robust z-score (median/MAD) against weekday expectation.
* Reorder  : demand forecast + safety stock + reorder point + review period.
* RFM      : recency/frequency/monetary quintile scoring and segmentation.
* Basket   : pairwise association rules (support / confidence / lift).
* Menu     : popularity vs margin quadrant (menu engineering).
"""
from __future__ import annotations

import math
import statistics
from collections import Counter, defaultdict
from datetime import date, datetime, timedelta
from typing import Any


# --------------------------------------------------------------------------- utils
def to_date(value: str) -> date:
    return datetime.strptime(str(value)[:10], "%Y-%m-%d").date()


def fmt_idr(value: float) -> str:
    return "Rp " + f"{round(float(value)):,}".replace(",", ".")


def fmt_num(value: float, decimals: int = 0) -> str:
    text = f"{float(value):,.{decimals}f}"
    return text.replace(",", "X").replace(".", ",").replace("X", ".")


def smape(actual: list[float], predicted: list[float]) -> float:
    pairs = [(a, p) for a, p in zip(actual, predicted) if abs(a) + abs(p) > 1e-9]
    if not pairs:
        return 999.0
    return 100.0 * sum(abs(p - a) / ((abs(a) + abs(p)) / 2) for a, p in pairs) / len(pairs)


# ---------------------------------------------------------------------- forecasting
def _moving_average(series: list[float], horizon: int) -> list[float]:
    window = min(7, len(series)) if series else 1
    base = sum(series[-window:]) / max(1, window)
    return [max(0.0, base) for _ in range(horizon)]


def _naive(series: list[float], horizon: int) -> list[float]:
    base = series[-1] if series else 0.0
    return [max(0.0, base) for _ in range(horizon)]


def _seasonal_naive(series: list[float], horizon: int, season: int = 7) -> list[float]:
    if len(series) < season:
        return _naive(series, horizon)
    out = []
    for h in range(1, horizon + 1):
        out.append(max(0.0, series[-season + ((h - 1) % season)]))
    return out


def _holt(series: list[float], horizon: int, alpha: float = 0.35, beta: float = 0.12) -> list[float]:
    if len(series) < 3:
        return _naive(series, horizon)
    level = series[0]
    trend = series[1] - series[0]
    for value in series[1:]:
        prev_level = level
        level = alpha * value + (1 - alpha) * (level + trend)
        trend = beta * (level - prev_level) + (1 - beta) * trend
    return [max(0.0, level + trend * h) for h in range(1, horizon + 1)]


def _holt_winters(series: list[float], horizon: int, season: int = 7,
                  alpha: float = 0.3, beta: float = 0.08, gamma: float = 0.25) -> list[float]:
    n = len(series)
    if n < season * 2:
        return _holt(series, horizon)
    first = series[:season]
    second = series[season:2 * season]
    level = sum(first) / season
    trend = (sum(second) / season - level) / season
    seasonal = [series[i] - level for i in range(season)]
    for i in range(season, n):
        value = series[i]
        season_idx = i % season
        prev_level = level
        level = alpha * (value - seasonal[season_idx]) + (1 - alpha) * (level + trend)
        trend = beta * (level - prev_level) + (1 - beta) * trend
        seasonal[season_idx] = gamma * (value - level) + (1 - gamma) * seasonal[season_idx]
    out = []
    for h in range(1, horizon + 1):
        out.append(max(0.0, level + trend * h + seasonal[(n + h - 1) % season]))
    return out


MODELS = {
    "naive": lambda s, h: _naive(s, h),
    "seasonal_naive": lambda s, h: _seasonal_naive(s, h),
    "moving_average_7": lambda s, h: _moving_average(s, h),
    "holt_linear": lambda s, h: _holt(s, h),
    "holt_winters_additive": lambda s, h: _holt_winters(s, h),
}


def forecast_series(series: list[float], horizon: int = 14, min_train: int = 35) -> dict[str, Any]:
    """Select the model with the lowest backtest sMAPE, then forecast."""
    series = [max(0.0, float(v)) for v in series]
    if len(series) < 21:
        return {
            "model": "insufficient_data", "mape": None, "horizon": horizon,
            "forecast": [], "residual_std": 0.0,
            "message": "Butuh minimal 21 hari data penjualan untuk forecast yang dapat diuji.",
        }
    split = len(series) - min(28, max(14, len(series) // 5))
    train, test = series[:split], series[split:]
    scores: dict[str, float] = {}
    best_name = "moving_average_7"
    best_score = float("inf")
    for name, fn in MODELS.items():
        try:
            pred = fn(train, len(test))
            score = smape(test, pred)
        except Exception:
            score = 999.0
        scores[name] = round(score, 2)
        if score < best_score:
            best_score, best_name = score, name
    # Residual error of the chosen model on the holdout window.
    holdout_pred = MODELS[best_name](train, len(test))
    residuals = [abs(a - p) for a, p in zip(test, holdout_pred)]
    residual_std = statistics.pstdev(residuals) if len(residuals) > 1 else (residuals[0] if residuals else 0.0)
    points = MODELS[best_name](series, horizon)
    z = 1.282  # 80% interval
    today = date.today()
    forecast = []
    for i, value in enumerate(points, start=1):
        band = z * residual_std * math.sqrt(1 + i / max(7, horizon))
        forecast.append({
            "date": (today + timedelta(days=i)).isoformat(),
            "value": round(value, 2),
            "low": round(max(0.0, value - band), 2),
            "high": round(value + band, 2),
        })
    return {
        "model": best_name,
        "mape": round(best_score, 2) if best_score < 900 else None,
        "horizon": horizon,
        "forecast": forecast,
        "residual_std": round(residual_std, 2),
        "model_scores": scores,
        "message": None,
    }


# ------------------------------------------------------------------------ dataset
def daily_series(orders: list[dict], days: int = 120) -> list[dict]:
    by_date: dict[str, float] = defaultdict(float)
    counts: dict[str, int] = defaultdict(int)
    for order in orders:
        day = str(order.get("created_at", ""))[:10]
        if not day:
            continue
        by_date[day] += float(order.get("grand_total") or 0)
        counts[day] += 1
    today = date.today()
    out = []
    for i in range(days - 1, -1, -1):
        day = (today - timedelta(days=i)).isoformat()
        out.append({"date": day, "value": round(by_date.get(day, 0.0), 2), "orders": counts.get(day, 0)})
    return out


class Dataset:
    def __init__(self, raw: dict[str, Any]):
        self.raw = raw
        self.orders = raw.get("orders", []) or []
        self.items = raw.get("items", []) or []
        self.products = {int(p["id"]): p for p in (raw.get("products", []) or [])}
        self.stock: dict[int, float] = defaultdict(float)
        for row in raw.get("stock", []) or []:
            self.stock[int(row["product_id"])] += float(row.get("qty") or 0)
        self.customers = raw.get("customers", []) or []
        self.order_by_id = {int(o["id"]): o for o in self.orders}
        self.items_by_order: dict[int, list[dict]] = defaultdict(list)
        for item in self.items:
            try:
                self.items_by_order[int(item["order_id"])].append(item)
            except (KeyError, TypeError, ValueError):
                continue

    # -------------------------------------------------------------- aggregations
    def window(self, days: int) -> tuple[str, str]:
        end = date.today()
        start = end - timedelta(days=days - 1)
        return start.isoformat(), end.isoformat()

    def orders_between(self, start: str, end: str) -> list[dict]:
        return [o for o in self.orders if start <= str(o.get("created_at", ""))[:10] <= end]

    def revenue(self, days: int) -> dict[str, Any]:
        start, end = self.window(days)
        rows = self.orders_between(start, end)
        revenue = sum(float(o.get("grand_total") or 0) for o in rows)
        return {"start": start, "end": end, "orders": len(rows), "revenue": round(revenue, 2)}

    def product_sales(self, days: int = 30) -> list[dict]:
        start, end = self.window(days)
        agg: dict[str, dict] = {}
        for item in self.items:
            created = str(item.get("created_at", ""))[:10]
            if not (start <= created <= end):
                continue
            name = str(item.get("name") or "Produk")
            bucket = agg.setdefault(name, {"name": name, "qty": 0.0, "revenue": 0.0, "cost": 0.0})
            qty = float(item.get("qty") or 0)
            bucket["qty"] += qty
            bucket["revenue"] += float(item.get("line_total") or 0)
            bucket["cost"] += qty * float(item.get("unit_cost") or 0)
        rows = []
        for bucket in agg.values():
            margin = bucket["revenue"] - bucket["cost"]
            bucket["margin"] = round(margin, 2)
            bucket["margin_pct"] = round((margin / bucket["revenue"] * 100) if bucket["revenue"] > 0 else 0, 2)
            rows.append(bucket)
        rows.sort(key=lambda r: r["revenue"], reverse=True)
        return rows

    # ------------------------------------------------------------------ forecast
    def forecast(self, horizon: int = 14) -> dict[str, Any]:
        series = [row["value"] for row in daily_series(self.orders, 120)]
        result = forecast_series(series, horizon)
        history = daily_series(self.orders, 45)
        result["history"] = history
        return result

    # ----------------------------------------------------------------- anomalies
    def anomalies(self) -> dict[str, Any]:
        series = daily_series(self.orders, 90)
        by_weekday: dict[int, list[float]] = defaultdict(list)
        for row in series:
            weekday = to_date(row["date"]).weekday()
            by_weekday[weekday].append(row["value"])
        weekday_median = {wd: statistics.median(vals) for wd, vals in by_weekday.items() if vals}
        weekday_mad = {}
        for wd, vals in by_weekday.items():
            med = weekday_median[wd]
            mad = statistics.median([abs(v - med) for v in vals]) if vals else 0
            weekday_mad[wd] = mad * 1.4826 if mad > 0 else 0
        items = []
        for row in series[-45:]:
            wd = to_date(row["date"]).weekday()
            median = weekday_median.get(wd, 0)
            scale = weekday_mad.get(wd, 0)
            value = row["value"]
            if scale > 0:
                z = 0.6745 * (value - median) / scale
            else:
                z = 0.0 if abs(value - median) < 1 else (2.5 if value > median else -2.5)
            ratio = (value / median) if median > 0 else 0
            if abs(z) >= 3.5 or (median > 0 and (ratio >= 2.2 or ratio <= 0.35)):
                direction = "lonjakan" if value >= median else "penurunan"
                items.append({
                    "date": row["date"],
                    "value": round(value, 2),
                    "expected": round(median, 2),
                    "z": round(z, 2),
                    "severity": "high" if abs(z) >= 5 else "medium",
                    "note": f"{direction.capitalize()} {abs(round((ratio - 1) * 100, 1))}% vs median hari yang sama",
                })
        items.sort(key=lambda i: abs(i["z"]), reverse=True)
        return {"items": items[:10], "checked_days": 45, "method": "robust_zscore_weekday"}

    # ------------------------------------------------------------------- reorder
    def reorder(self, lead_time: int = 7, review_period: int = 7, service_z: float = 1.65) -> dict[str, Any]:
        start = (date.today() - timedelta(days=59)).isoformat()
        demand: dict[int, dict[str, float]] = defaultdict(lambda: defaultdict(float))
        names: dict[int, str] = {}
        for item in self.items:
            created = str(item.get("created_at", ""))[:10]
            if created < start:
                continue
            try:
                pid = int(item["product_id"])
            except (KeyError, TypeError, ValueError):
                continue
            demand[pid][created] += float(item.get("qty") or 0)
            names[pid] = str(item.get("name") or self.products.get(pid, {}).get("name", f"#{pid}"))
        items = []
        today = date.today()
        for pid, daily in demand.items():
            values = []
            for i in range(60):
                day = (today - timedelta(days=59 - i)).isoformat()
                values.append(daily.get(day, 0.0))
            avg_daily = sum(values) / 60
            if avg_daily <= 0:
                continue
            std_daily = statistics.pstdev(values) if len(values) > 1 else 0.0
            last14 = sum(values[-14:]) / 14
            prev14 = sum(values[-28:-14]) / 14
            trend = (last14 - prev14) / max(0.01, prev14)
            projected_daily = max(0.0, avg_daily * (1 + max(-0.5, min(0.5, trend * 0.5))))
            safety = service_z * std_daily * math.sqrt(lead_time)
            reorder_point = projected_daily * lead_time + safety
            stock = float(self.stock.get(pid, 0.0))
            recommended = max(0.0, reorder_point + projected_daily * review_period - stock)
            days_cover = (stock / projected_daily) if projected_daily > 0 else None
            configured_rop = float(self.products.get(pid, {}).get("reorder_point") or 0)
            if days_cover is not None and days_cover <= lead_time:
                priority = "high"
            elif stock <= configured_rop or (days_cover is not None and days_cover <= lead_time + 7):
                priority = "medium"
            else:
                priority = "low"
            if recommended <= 0 and stock > configured_rop and priority == "low":
                continue
            reason = f"Rata-rata {fmt_num(avg_daily, 1)}/hari, cover {fmt_num(days_cover, 1) if days_cover is not None else '∞'} hari"
            if trend > 0.15:
                reason += ", tren naik"
            elif trend < -0.15:
                reason += ", tren turun"
            items.append({
                "product_id": pid,
                "name": names.get(pid, f"#{pid}"),
                "stock": round(stock, 2),
                "avg_daily": round(avg_daily, 3),
                "days_cover": round(days_cover, 1) if days_cover is not None else None,
                "reorder_point": round(reorder_point, 2),
                "recommended_qty": round(recommended, 0),
                "unit_cost": float(self.products.get(pid, {}).get("cost") or 0),
                "priority": priority,
                "reason": reason,
            })
        order = {"high": 0, "medium": 1, "low": 2}
        items.sort(key=lambda i: (order[i["priority"]], -(i["days_cover"] if i["days_cover"] is not None else 999)))
        return {
            "items": items[:15],
            "lead_time_days": lead_time,
            "review_period_days": review_period,
            "service_level": 0.95,
            "total_recommended_value": round(sum(i["recommended_qty"] * i["unit_cost"] for i in items), 2),
        }

    # ----------------------------------------------------------------------- RFM
    def rfm(self) -> dict[str, Any]:
        today = date.today()
        agg: dict[int, dict[str, Any]] = {}
        for order in self.orders:
            cid = order.get("customer_id")
            if not cid:
                continue
            cid = int(cid)
            bucket = agg.setdefault(cid, {"frequency": 0, "monetary": 0.0, "last": None})
            bucket["frequency"] += 1
            bucket["monetary"] += float(order.get("grand_total") or 0)
            created = str(order.get("created_at", ""))[:10]
            if created and (bucket["last"] is None or created > bucket["last"]):
                bucket["last"] = created
        names = {int(c["id"]): c.get("name", f"Pelanggan #{c['id']}") for c in self.customers}
        rows = []
        for cid, bucket in agg.items():
            recency = (today - to_date(bucket["last"])).days if bucket["last"] else 999
            rows.append({
                "customer_id": cid,
                "name": names.get(cid, f"Pelanggan #{cid}"),
                "recency": recency,
                "frequency": bucket["frequency"],
                "monetary": round(bucket["monetary"], 2),
            })
        if not rows:
            return {"segments": [], "customers": [], "method": "rfm_quintile"}

        def score(value: float, key: str, reverse: bool = False) -> int:
            ordered = sorted({r[key] for r in rows})
            if len(ordered) < 2:
                return 3
            rank = sum(1 for v in ordered if v <= value) / len(ordered)
            if reverse:
                rank = 1 - rank
            return max(1, min(5, int(math.ceil(rank * 5))))

        for row in rows:
            row["r_score"] = score(row["recency"], "recency", reverse=True)
            row["f_score"] = score(row["frequency"], "frequency")
            row["m_score"] = score(row["monetary"], "monetary")
            r, f = row["r_score"], row["f_score"]
            if r >= 4 and f >= 4:
                segment = "Champions"
            elif r >= 3 and f >= 3:
                segment = "Loyal"
            elif r >= 3 and f <= 2:
                segment = "Potential"
            elif r <= 2 and f >= 3:
                segment = "At Risk"
            elif f == 1 and r >= 4:
                segment = "New"
            else:
                segment = "Hibernating"
            row["segment"] = segment
        segments = []
        for name in ["Champions", "Loyal", "Potential", "New", "At Risk", "Hibernating"]:
            members = [r for r in rows if r["segment"] == name]
            if not members:
                continue
            segments.append({
                "name": name,
                "count": len(members),
                "revenue": round(sum(m["monetary"] for m in members), 2),
                "avg_recency": round(sum(m["recency"] for m in members) / len(members), 1),
                "avg_frequency": round(sum(m["frequency"] for m in members) / len(members), 1),
                "avg_monetary": round(sum(m["monetary"] for m in members) / len(members), 2),
            })
        rows.sort(key=lambda r: r["monetary"], reverse=True)
        return {"segments": segments, "customers": rows[:30], "method": "rfm_quintile"}

    # -------------------------------------------------------------------- basket
    def basket(self, min_support: float = 0.02, min_confidence: float = 0.12, limit: int = 12) -> dict[str, Any]:
        baskets: list[set[str]] = []
        for order_id, items in self.items_by_order.items():
            names = {str(i.get("name") or "") for i in items if float(i.get("qty") or 0) > 0}
            names.discard("")
            if len(names) >= 2:
                baskets.append(names)
        total = len(baskets)
        if total < 5:
            return {"rules": [], "baskets": total, "message": "Butuh minimal 5 transaksi multi-item."}
        pair_counts: Counter = Counter()
        item_counts: Counter = Counter()
        for basket in baskets:
            for name in basket:
                item_counts[name] += 1
            ordered = sorted(basket)
            for i in range(len(ordered)):
                for j in range(i + 1, len(ordered)):
                    pair_counts[(ordered[i], ordered[j])] += 1
        rules = []
        for (a, b), count in pair_counts.items():
            support = count / total
            if support < min_support:
                continue
            for antecedent, consequent in ((a, b), (b, a)):
                confidence = count / max(1, item_counts[antecedent])
                if confidence < min_confidence:
                    continue
                lift = confidence / max(0.0001, item_counts[consequent] / total)
                rules.append({
                    "antecedent": antecedent,
                    "consequent": consequent,
                    "support": round(support, 4),
                    "confidence": round(confidence, 4),
                    "lift": round(lift, 3),
                    "count": count,
                })
        rules.sort(key=lambda r: (r["lift"], r["confidence"]), reverse=True)
        return {"rules": rules[:limit], "baskets": total, "min_support": min_support}

    # ---------------------------------------------------------------------- menu
    def menu(self, days: int = 30) -> dict[str, Any]:
        rows = [r for r in self.product_sales(days) if r["qty"] > 0]
        if not rows:
            return {"items": [], "summary": {}, "message": "Belum ada penjualan produk."}
        med_qty = statistics.median([r["qty"] for r in rows])
        med_margin = statistics.median([r["margin_pct"] for r in rows])
        labels = {
            "star": ("Star", "Pertahankan kualitas & visibilitas; menu paling menguntungkan."),
            "plowhorse": ("Plowhorse", "Populer tetapi margin rendah; pertimbangkan paket bundling atau naikkan harga kecil."),
            "puzzle": ("Puzzle", "Margin baik tetapi kurang populer; promosikan atau reposisi di menu."),
            "dog": ("Dog", "Margin dan popularitas rendah; evaluasi resep, harga, atau hentikan."),
        }
        items = []
        for row in rows:
            if row["qty"] >= med_qty and row["margin_pct"] >= med_margin:
                cls = "star"
            elif row["qty"] >= med_qty:
                cls = "plowhorse"
            elif row["margin_pct"] >= med_margin:
                cls = "puzzle"
            else:
                cls = "dog"
            label, recommendation = labels[cls]
            items.append({
                "name": row["name"], "qty": row["qty"], "revenue": row["revenue"],
                "margin_pct": row["margin_pct"], "class": cls, "label": label,
                "recommendation": recommendation,
            })
        summary = {
            "stars": sum(1 for i in items if i["class"] == "star"),
            "plowhorses": sum(1 for i in items if i["class"] == "plowhorse"),
            "puzzles": sum(1 for i in items if i["class"] == "puzzle"),
            "dogs": sum(1 for i in items if i["class"] == "dog"),
            "median_qty": round(med_qty, 2),
            "median_margin_pct": round(med_margin, 2),
        }
        return {"items": items, "summary": summary, "method": "menu_engineering_median"}

    # ------------------------------------------------------------------- summary
    def summary(self) -> dict[str, Any]:
        current = self.revenue(30)
        previous_end = date.today() - timedelta(days=30)
        previous_start = previous_end - timedelta(days=29)
        prev_orders = self.orders_between(previous_start.isoformat(), previous_end.isoformat())
        prev_revenue = sum(float(o.get("grand_total") or 0) for o in prev_orders)
        items = [i for i in self.items if current["start"] <= str(i.get("created_at", ""))[:10] <= current["end"]]
        cost = sum(float(i.get("qty") or 0) * float(i.get("unit_cost") or 0) for i in items)
        revenue = current["revenue"]
        margin_pct = ((revenue - cost) / revenue * 100) if revenue > 0 else 0
        growth = ((revenue - prev_revenue) / prev_revenue * 100) if prev_revenue > 0 else 0
        aov = revenue / current["orders"] if current["orders"] else 0
        top = self.product_sales(30)
        series = daily_series(self.orders, 30)
        best_day = max(series, key=lambda r: r["value"]) if series else {"date": "-", "value": 0}
        reorder = self.reorder()
        watch = [i for i in reorder["items"] if i["priority"] in ("high", "medium")][:3]
        narrative = []
        if revenue > 0:
            direction = "naik" if growth >= 0 else "turun"
            narrative.append(
                f"Omzet 30 hari terakhir {fmt_idr(revenue)} dari {current['orders']} transaksi "
                f"({direction} {fmt_num(abs(growth), 1)}% vs periode sebelumnya)."
            )
            if top:
                narrative.append(f"Produk penyumbang omzet terbesar: {top[0]['name']} ({fmt_idr(top[0]['revenue'])}).")
            narrative.append(f"Margin kotor estimasi {fmt_num(margin_pct, 1)}%, rata-rata {fmt_idr(aov)} per nota.")
            if best_day["value"] > 0:
                narrative.append(f"Hari terbaik: {best_day['date']} dengan omzet {fmt_idr(best_day['value'])}.")
            if watch:
                narrative.append(
                    "Perlu reorder: " + ", ".join(f"{w['name']} (saran {int(w['recommended_qty'])})" for w in watch) + "."
                )
        else:
            narrative.append("Belum ada transaksi lunas dalam 30 hari terakhir.")
        return {
            "period_days": 30,
            "generated_at": datetime.utcnow().isoformat(timespec="seconds") + "Z",
            "kpis": {
                "revenue": round(revenue, 2),
                "orders": current["orders"],
                "aov": round(aov, 2),
                "gross_margin_pct": round(margin_pct, 2),
                "growth_pct": round(growth, 2),
                "prev_revenue": round(prev_revenue, 2),
            },
            "narrative": narrative,
            "health_score": self._health_score(revenue, margin_pct, growth, reorder),
        }

    def _health_score(self, revenue: float, margin_pct: float, growth: float, reorder: dict) -> int:
        if revenue <= 0:
            return 0
        score = 50
        score += min(20, max(-15, growth / 2))
        score += 15 if margin_pct >= 40 else (8 if margin_pct >= 25 else 0)
        high = sum(1 for i in reorder["items"] if i["priority"] == "high")
        score -= min(15, high * 3)
        return max(0, min(100, int(round(score))))

    # ------------------------------------------------------------------------ ask
    def ask(self, question: str) -> dict[str, Any]:
        q = (question or "").lower()
        generated = datetime.utcnow().isoformat(timespec="seconds") + "Z"
        if any(word in q for word in ["omzet", "penjualan", "revenue", "pendapatan"]):
            days = 7 if "7" in q or "minggu" in q else (1 if "hari ini" in q or "today" in q else 30)
            data = self.revenue(days)
            if data["orders"] == 0:
                answer = f"Tidak ada transaksi lunas dalam {days} hari terakhir."
            else:
                aov = data["revenue"] / data["orders"]
                answer = (f"Omzet {days} hari terakhir {fmt_idr(data['revenue'])} dari {data['orders']} transaksi "
                          f"(rata-rata {fmt_idr(aov)} per nota).")
            return {"intent": "revenue", "answer": answer, "data": data, "generated_at": generated}

        if any(word in q for word in ["terlaris", "paling laku", "best seller", "produk"]):
            rows = self.product_sales(30)
            if not rows:
                return {"intent": "top_products", "answer": "Belum ada penjualan produk dalam 30 hari terakhir.", "data": [], "generated_at": generated}
            top = rows[0]
            answer = (f"Produk terlaris 30 hari terakhir: {top['name']} — {fmt_num(top['qty'])} unit, "
                      f"omzet {fmt_idr(top['revenue'])} (margin {fmt_num(top['margin_pct'], 1)}%).")
            if len(rows) > 1:
                answer += " Berikutnya: " + ", ".join(r["name"] for r in rows[1:4]) + "."
            return {"intent": "top_products", "answer": answer, "data": rows[:5], "generated_at": generated}

        if any(word in q for word in ["reorder", "restock", "stok", "kulakan", "beli ulang"]):
            data = self.reorder()
            high = [i for i in data["items"] if i["priority"] == "high"]
            if not data["items"]:
                answer = "Semua stok berada di atas reorder point berdasarkan forecast 60 hari terakhir."
            elif high:
                first = high[0]
                answer = (f"{len(high)} produk berisiko habis. Prioritas: {first['name']} — stok {fmt_num(first['stock'])} "
                          f"(cover {first['days_cover']} hari), saran order {int(first['recommended_qty'])} unit.")
            else:
                first = data["items"][0]
                answer = (f"Belum ada risiko kritis, tetapi {first['name']} mendekati reorder point "
                          f"(stok {fmt_num(first['stock'])}, saran {int(first['recommended_qty'])} unit).")
            return {"intent": "reorder", "answer": answer, "data": data["items"][:6], "generated_at": generated}

        if any(word in q for word in ["pelanggan", "loyal", "customer", "member"]):
            data = self.rfm()
            if not data["segments"]:
                return {"intent": "rfm", "answer": "Belum ada pelanggan dengan transaksi tercatat.", "data": [], "generated_at": generated}
            champions = next((s for s in data["segments"] if s["name"] == "Champions"), None)
            if champions:
                answer = (f"{champions['count']} pelanggan Champions menyumbang {fmt_idr(champions['revenue'])}. "
                          f"Rata-rata frekuensi {champions['avg_frequency']} transaksi dengan belanja {fmt_idr(champions['avg_monetary'])}.")
            else:
                top_seg = max(data["segments"], key=lambda s: s["revenue"])
                answer = f"Segmen terbesar saat ini {top_seg['name']} ({top_seg['count']} pelanggan, {fmt_idr(top_seg['revenue'])})."
            return {"intent": "rfm", "answer": answer, "data": data["segments"], "generated_at": generated}

        if any(word in q for word in ["forecast", "ramalan", "prediksi", "besok", "minggu depan"]):
            data = self.forecast(14)
            if data["forecast"]:
                total7 = sum(p["value"] for p in data["forecast"][:7])
                answer = (f"Forecast 7 hari ke depan sekitar {fmt_idr(total7)} (model {data['model']}, "
                          f"akurasi backtest {fmt_num(100 - (data['mape'] or 0), 1)}%).")
            else:
                answer = data.get("message") or "Data belum cukup untuk forecast."
            return {"intent": "forecast", "answer": answer, "data": data, "generated_at": generated}

        if any(word in q for word in ["jam", "sibuk", "ramai", "peak"]):
            hourly: Counter = Counter()
            revenue: Counter = Counter()
            for order in self.orders[-2000:]:
                created = str(order.get("created_at", ""))
                if len(created) >= 13:
                    hour = int(created[11:13])
                    hourly[hour] += 1
                    revenue[hour] += float(order.get("grand_total") or 0)
            if not hourly:
                return {"intent": "hourly", "answer": "Belum ada data jam transaksi.", "data": [], "generated_at": generated}
            best = max(hourly, key=lambda h: hourly[h])
            answer = f"Jam tersibuk: {best:02d}:00–{best+1:02d}:00 dengan {hourly[best]} transaksi (omzet {fmt_idr(revenue[best])})."
            return {"intent": "hourly", "answer": answer, "data": sorted(hourly.items()), "generated_at": generated}

        if any(word in q for word in ["margin", "laba", "untung", "profit"]):
            data = self.summary()
            answer = (f"Margin kotor estimasi 30 hari terakhir {fmt_num(data['kpis']['gross_margin_pct'], 1)}% "
                      f"dari omzet {fmt_idr(data['kpis']['revenue'])}. Ini sebelum biaya operasional.")
            return {"intent": "margin", "answer": answer, "data": data["kpis"], "generated_at": generated}

        data = self.summary()
        answer = "Saya bisa menjawab omzet, produk terlaris, reorder stok, pelanggan loyal, forecast, jam sibuk, dan margin. Ringkasan: " + " ".join(data["narrative"][:2])
        return {"intent": "summary", "answer": answer, "data": data, "generated_at": generated}
