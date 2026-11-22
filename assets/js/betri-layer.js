/* BOLU 3 - layer lokal BETRI, data dari pengguna (raster TIFF, ProfileTool, PETA GARIS).
   Layer ini tambahan saja: tidak mengganti data BATNAS/GEBCO maupun API kedalaman.
   TIFF 94-142 BT, 11 LS-7 LU (EPSG:4326) ditampilkan sebagai image overlay PNG. */
(function () {
    'use strict';
    function init() {
        var map = window.BOLU_BETRI_MAP;
        if (!map || !window.L || window.BOLU_BETRI_INITIALIZED) return;
        window.BOLU_BETRI_INITIALIZED = true;
        var dir = 'assets/data/betri/';
        var bounds = [[-11,94],[7,142]];
        var raster = L.imageOverlay(dir+'betri-raster.png', bounds, {opacity:.76, interactive:false, attribution:'Data BETRI (hasil olahan lokal)'});
        var loaded = {}, pending = {};
        var style = {
            contour: function (f) {
                var elev=Number(f.properties && f.properties.ELEV);
                return {color:elev <= -3000 ? '#2542bd' : elev <= -500 ? '#1e7fca' : '#e2a34d', weight:1.2, opacity:.80};
            },
            points: function () {return {radius:3.5,color:'#852f11',fillColor:'#ffbc49',fillOpacity:.87,weight:.7};}
        };
        var show = {raster:true,contour:false,points:false};
        var names = {contour:'betri-kontur.geojson',points:'betri-titik.geojson'};
        var status = document.getElementById('betriStatus');
        function setStatus(msg){if(status) status.textContent=msg;}
        function load(key) {
            if (loaded[key]) {if(show[key]) loaded[key].addTo(map);return;}
            if (pending[key]) return;
            setStatus('Memuat '+(key==='contour'?'kontur':'titik')+' BETRI ...');
            pending[key]=fetch(dir+names[key]).then(function(r){if(!r.ok)throw new Error('HTTP '+r.status);return r.json();})
            .then(function(g){
                var layer=L.geoJSON(g, key==='contour' ? {
                    style:style.contour,
                    onEachFeature:function(f,l){
                        var x=f.properties && f.properties.ELEV;
                        if(x!==undefined&&x!==null)l.bindPopup('<b>Kontur BETRI</b><br>Elevasi: '+Number(x).toLocaleString('id-ID')+' m');
                    }
                } : {
                    pointToLayer:function(f,latlng){return L.circleMarker(latlng,style.points());},
                    onEachFeature:function(f,l){
                        var x=f.properties && f.properties.Value;
                        if(x!==undefined&&x!==null)l.bindPopup('<b>Titik BETRI</b><br>Nilai: '+Number(x).toLocaleString('id-ID'));
                    }
                });
                loaded[key]=layer;if(show[key])layer.addTo(map);
                setStatus('Layer BETRI berhasil dimuat');
            }).catch(function(e){setStatus('Gagal memuat '+key+': '+e.message+'; periksa folder assets/data/betri');})
            .finally(function(){pending[key]=null;});
        }
        raster.addTo(map);
        document.querySelectorAll('.betri-chk').forEach(function(chk){
            chk.addEventListener('change',function(){
                var key=chk.dataset.betri;show[key]=chk.checked;
                if(key==='raster'){if(show[key])raster.addTo(map);else map.removeLayer(raster);return;}
                if(show[key])load(key);else if(loaded[key])map.removeLayer(loaded[key]);
            });
        });
        var zoom=document.getElementById('betriZoom');
        if(zoom)zoom.addEventListener('click',function(){map.fitBounds(bounds,{padding:[10,10]});});
    }
    window.addEventListener('bolu:batimetri-ready',init);
    if(window.BOLU_BETRI_MAP)init();
})();
