<?php
declare(strict_types=1);

namespace MeshClient;

/**
 * Šablona pro HA render_template – vrací stav sledovaných zařízení, companionů, MQTT brokerů, slunce
 * a kredit zpráv z limitu provozu (senzor Request Rate Limiter, režim Governed).
 * HA ji sám přepočítá při změně (iterace přes celou doménu je omezená na 1× za minutu).
 */
final class HaTemplate
{
    public static function snapshot(): string
    {
        return <<<'JINJA'
{%- set ns = namespace(dev=[], mqtt=[], comp=[], sens=[], pre=[], disc=[], rl=none) -%}
{%- for s in states.binary_sensor -%}
  {%- if s.entity_id.startswith('binary_sensor.meshcore_') -%}
    {%- if '_online_' in s.entity_id -%}
      {%- set ns.dev = ns.dev + [{'id': s.entity_id, 'state': s.state, 'name': s.attributes.get('friendly_name'), 'changed': s.last_changed.isoformat()}] -%}
      {%- set ns.pre = ns.pre + ['sensor.meshcore_' ~ s.entity_id[23:33] ~ '_'] -%}
    {%- elif s.entity_id.endswith('_contact') and s.state == 'discovered' -%}
      {%- set ns.disc = ns.disc + [{'pk': s.attributes.get('public_key') or s.attributes.get('pubkey_prefix'), 'name': s.attributes.get('adv_name'), 'type': s.attributes.get('type'), 'last_advert': s.attributes.get('last_advert'), 'path_len': s.attributes.get('out_path_len'), 'lat': s.attributes.get('latitude'), 'lon': s.attributes.get('longitude')}] -%}
    {%- elif '_mqtt_broker_' in s.entity_id and s.entity_id.endswith('_connection') -%}
      {%- set ns.mqtt = ns.mqtt + [{'id': s.entity_id, 'state': s.state, 'server': s.attributes.get('server'), 'n': s.attributes.get('broker_number'), 'changed': s.last_changed.isoformat()}] -%}
    {%- endif -%}
  {%- endif -%}
{%- endfor -%}
{%- for s in states.sensor -%}
  {%- if s.entity_id.startswith('sensor.meshcore_') and '_node_status_' in s.entity_id -%}
    {%- set ns.comp = ns.comp + [{'id': s.entity_id, 'state': s.state, 'name': s.attributes.get('friendly_name'), 'fw': device_attr(s.entity_id, 'sw_version'), 'model': device_attr(s.entity_id, 'model')}] -%}
    {%- set ns.pre = ns.pre + [s.entity_id[:23]] -%}
  {%- elif ns.rl is none and s.entity_id.startswith('sensor.meshcore_') and '_rate_limiter_tokens' in s.entity_id and s.attributes.get('messages_credits') is not none -%}
    {%- set ns.rl = {'credits': s.attributes.get('messages_credits'), 'cap': s.attributes.get('messages_capacity'), 'per_hour': s.attributes.get('messages_refill_per_hour'), 'updated': s.last_updated.isoformat()} -%}
  {%- endif -%}
{%- endfor -%}
{%- for s in states.sensor -%}
  {%- if s.entity_id[:27] in ns.pre or s.entity_id[:23] in ns.pre -%}
    {%- set ns.sens = ns.sens + [{'id': s.entity_id, 'state': s.state, 'unit': s.attributes.get('unit_of_measurement'), 'name': s.attributes.get('friendly_name'), 'updated': s.last_updated.isoformat()}] -%}
  {%- endif -%}
{%- endfor -%}
{{ {'sun': {'state': states('sun.sun'), 'rising': state_attr('sun.sun', 'next_rising'), 'setting': state_attr('sun.sun', 'next_setting')}, 'devices': ns.dev, 'companions': ns.comp, 'mqtt': ns.mqtt, 'sensors': ns.sens, 'discovered': ns.disc, 'traffic': ns.rl} | to_json }}
JINJA;
    }
}
