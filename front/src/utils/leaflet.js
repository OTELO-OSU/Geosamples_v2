/** Leaflet + markercluster (le plugin attend un L global). */
import L from 'leaflet'
import 'leaflet.markercluster/dist/MarkerCluster.css'

window.L = L
let ready = null
export function loadLeaflet() {
  if (!ready) ready = import('leaflet.markercluster').then(() => L)
  return ready
}
export default L
