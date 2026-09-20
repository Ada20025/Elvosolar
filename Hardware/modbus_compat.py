# -*- coding: utf-8 -*-
"""
PyModbus kompatibilita - helper pre vsetky citania/zapisi.
Nova pymodbus (3.7+) pouziva device_id, starsia slave alebo unit.
Pouzitie:
    from modbus_compat import pm_call
    result = pm_call(client.read_holding_registers, address=40427, count=1, unit=0)
"""
import inspect


def pm_unit_param_name(client):
    """Zisti ake klucone slovo aktualna pymodbus verzia akceptuje."""
    for attr in ('write_register', 'read_holding_registers'):
        fn = getattr(client, attr, None)
        if fn is None:
            continue
        try:
            params = inspect.signature(fn).parameters
        except (TypeError, ValueError):
            continue
        for candidate in ('device_id', 'slave', 'unit'):
            if candidate in params:
                return candidate
    return 'slave'  # fallback


def pm_call(fn, unit=None, **kwargs):
    """Zavolaj pymodbus funkciu so spravnym nazvom parametra pre unit id."""
    if unit is not None:
        kwargs['unit'] = unit
    last_err = None
    for param in ('device_id', 'slave', 'unit'):
        if param not in kwargs:
            continue
        try:
            return fn(**kwargs)
        except TypeError as e:
            if param in str(e):
                # Zly nazov parametra - presun hodnotu na dalsi kandidat
                val = kwargs.pop(param)
                next_p = {'device_id': 'slave', 'slave': 'unit', 'unit': None}[param]
                if next_p:
                    kwargs[next_p] = val
                last_err = e
                continue
            raise
    if last_err:
        raise last_err
    return fn(**kwargs)
