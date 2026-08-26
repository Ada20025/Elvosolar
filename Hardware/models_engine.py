# models_engine.py

MODELY_CONFIG = {
    "1": {
        "porovnanie": "nad_nulou", 
        "on_limit": 100, 
        "off_limit": 0, 
        "label": "Model 1: Ochrana pred zápornou cenou"
    },
    "2": {
        "porovnanie": "nad_nulou", 
        "on_limit": 100, 
        "off_limit": 0, 
        "label": "Model 2: Predaj do siete (Arbitráž)"
    },
    "3": {
        "porovnanie": "pod_priemerom", 
        "max_soc": 95, 
        "label": "Model 3: Nákup pod priemerom / Odber z batérie"
    },
    "4": {
        "porovnanie": "arbitraz", 
        "max_soc": 95, 
        "min_soc": 15, 
        "label": "Model 4: Nákup pod priemerom / Predaj v špičke"
    },
    "5": {
        "porovnanie": "dovolenka",
        "max_soc": 95,
        "min_soc": 15,
        "label": "Model 5: Dovolenkový fixný režim (Zákaz odberu)"
    }
}

def spusti_regulacnu_logiku(model_id: str, cena_aktualna: float, stats: dict, soc: float, core, slave_id: int) -> str:
    """Spustí vybraný regulačný model nad striedačom priamo cez Modbus zbernicu."""
    cfg = MODELY_CONFIG.get(str(model_id))
    if not cfg:
        return f"Varovanie: Regulačný model {model_id} nie je konfigurovaný."

    avg = stats.get('avg', 80.0) if stats else 80.0
    vynut_on = False

    porovnanie = cfg["porovnanie"]
    
    if porovnanie == "nad_nulou":
        if cena_aktualna > 0.0:
            vynut_on = True
            
    elif porovnanie == "pod_priemerom":
        if cena_aktualna < avg:
            if soc < cfg.get("max_soc", 95):
                vynut_on = True
                
    elif porovnanie == "arbitraz":
        if cena_aktualna < avg:
            if soc < cfg.get("max_soc", 95):
                vynut_on = True
        elif cena_aktualna >= avg:
            if soc > cfg.get("min_soc", 15):
                vynut_on = True

    elif porovnanie == "dovolenka":
        # Ak sme na dovolenke, sieťový odber pre striedač je zakázaný.
        # Výnimka nastáva iba ak SOC úložiska klesne na limit (15%) - vtedy dočasne povolíme dobíjanie zo siete pre zachovanie zdravia akumulátora.
        if soc <= cfg.get("min_soc", 15):
            vynut_on = True  
        else:
            vynut_on = False 

    stav = "ON" if vynut_on else "OFF"
    core.write_command(slave_id, stav)
    
    return f"[{cfg['label']}] Menič prepnutý na {stav} (Cena: {cena_aktualna:.2f} EUR, SoC: {soc:.0f}%)"