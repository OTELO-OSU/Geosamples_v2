/** Fonds de carte disponibles (choisis par site) et couches superposables. */
const geopf = (layer, fmt = 'image/png', style = 'normal') =>
  `https://data.geopf.fr/wmts?SERVICE=WMTS&REQUEST=GetTile&VERSION=1.0.0&LAYER=${layer}&STYLE=${style}&TILEMATRIXSET=PM&FORMAT=${fmt}&TILEMATRIX={z}&TILEROW={y}&TILECOL={x}`

export const BASEMAPS = {
  osm: { label: 'OpenStreetMap', url: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', attribution: '© contributeurs OpenStreetMap', maxZoom: 19 },
  opentopo: { label: 'Relief (OpenTopoMap)', url: 'https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', attribution: '© OpenStreetMap, SRTM · © OpenTopoMap (CC-BY-SA)', maxZoom: 17 },
  'ign-plan': { label: 'Plan IGN', url: geopf('GEOGRAPHICALGRIDSYSTEMS.PLANIGNV2'), attribution: '© IGN — Géoplateforme', maxZoom: 19 },
  'ign-ortho': { label: 'Photographies aériennes IGN', url: geopf('ORTHOIMAGERY.ORTHOPHOTOS', 'image/jpeg'), attribution: '© IGN — Géoplateforme', maxZoom: 19 },
  'esri-imagery': { label: 'Imagerie satellite (Esri)', url: 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', attribution: 'Tiles © Esri', maxZoom: 19 },
}

export const OVERLAYS = {
  'brgm-geol': {
    label: 'Carte géologique 1/50 000 (BRGM)',
    wms: 'https://geoservices.brgm.fr/geologie',
    options: { layers: 'SCAN_H_GEOL50', format: 'image/png', transparent: true, opacity: 0.6, attribution: '© BRGM' },
  },
}
