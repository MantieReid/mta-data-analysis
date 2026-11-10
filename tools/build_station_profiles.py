"""Turn output/station_heat.json (by complex id) into the slug-keyed file the plugin and the map use,
and tag each station with a personality from its weekly pattern.

Writes wordpress-plugin/mta-ridership/data/station-heat.json:
  {"from","to","weeks","s": {slug: [168 ints, Monday 0:00 first]}, "p": {slug: type code}}
Personality (entries only; a typical pattern, not a statement of who the riders are):
  night    - at least 11% of the week's entries between 10 PM and 4 AM
  weekend  - Saturday+Sunday daily average at least 85% of the weekday average
  morning  - weekday 6-10 AM entries at least 1.25x the weekday 4-8 PM entries
  evening  - weekday 4-8 PM entries at least 1.25x the weekday 6-10 AM entries
  allday   - everything else (rush hours of similar size, busy through the day)
"""
import json, os, sys
root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
heat = json.load(open(os.path.join(root, "output", "station_heat.json")))
slugs = json.load(open(os.path.join(root, "output", "station_slugs.json")))  # complex id -> [slug, name, borough]
s, p, counts = {}, {}, {}
for cid, v in heat["stations"].items():
    if cid not in slugs:
        continue
    slug = slugs[cid][0]
    s[slug] = v
    wk = [sum(v[d * 24:(d + 1) * 24]) for d in range(7)]
    wkday = sum(wk[:5]) / 5 or 1
    wkend = sum(wk[5:]) / 2
    tot = sum(v) or 1
    night = sum(v[d * 24 + h] for d in range(7) for h in (22, 23, 0, 1, 2, 3)) / tot
    am = sum(v[d * 24 + h] for d in range(5) for h in range(6, 10))
    pm = sum(v[d * 24 + h] for d in range(5) for h in range(16, 20))
    if tot < 7 * 150:
        t = "allday"
    elif night >= 0.11:
        t = "night"
    elif wkend / wkday >= 0.85:
        t = "weekend"
    elif am >= 1.25 * pm:
        t = "morning"
    elif pm >= 1.25 * am:
        t = "evening"
    else:
        t = "allday"
    p[slug] = t
    counts[t] = counts.get(t, 0) + 1
out = os.path.join(root, "wordpress-plugin", "mta-ridership", "data", "station-heat.json")
json.dump({"from": heat["from"], "to": heat["to"], "weeks": heat["weeks"], "s": s, "p": p}, open(out, "w"), separators=(",", ":"))
print(len(s), counts, os.path.getsize(out))
if len(sys.argv) > 1:
    rank = sorted(s, key=lambda k: -sum(s[k]))
    for t in counts:
        print(t, [k for k in rank if p[k] == t][:8])
