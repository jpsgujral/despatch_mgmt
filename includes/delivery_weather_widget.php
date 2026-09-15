<?php
/**
 * Delivery Location Weather Widget Component
 * Integrates with Open-Meteo (Free, No API key required)
 * Provides live weather, temperature, rain risk, humidity, wind, and 2-day forecast
 */
?>
<style>
.dms-weather-card {
    background: linear-gradient(135deg, #f0fdf4 0%, #e0f2fe 100%);
    border: 1px solid #bae6fd;
    border-left: 4px solid #0284c7;
    border-radius: 8px;
    padding: 10px 14px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.04);
    font-size: 0.85rem;
    transition: all 0.2s ease;
}
.dms-weather-card:hover {
    box-shadow: 0 4px 12px rgba(2,132,199,0.12);
}
.dms-weather-temp {
    font-size: 1.35rem;
    font-weight: 700;
    color: #0369a1;
    line-height: 1;
}
.dms-weather-icon {
    font-size: 1.6rem;
    line-height: 1;
}
.dms-weather-risk-low {
    background: #dcfce7;
    color: #15803d;
    border: 1px solid #bbf7d0;
}
.dms-weather-risk-med {
    background: #fef9c3;
    color: #a16207;
    border: 1px solid #fde047;
}
.dms-weather-risk-high {
    background: #fee2e2;
    color: #b91c1c;
    border: 1px solid #fca5a5;
    animation: dmsPulse 2s infinite;
}
@keyframes dmsPulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.8; }
}
.dms-forecast-day {
    background: rgba(255,255,255,0.7);
    border: 1px solid #e0f2fe;
    border-radius: 6px;
    padding: 4px 8px;
    font-size: 0.76rem;
    text-align: center;
    min-width: 95px;
}
</style>

<script>
if (!window.DMSWeather) {
window.DMSWeather = (function() {
    var cache = {};

    var WMO_CODES = {
        0:  { label: 'Clear Sky', icon: '☀️', risk: 'Low' },
        1:  { label: 'Mainly Clear', icon: '🌤️', risk: 'Low' },
        2:  { label: 'Partly Cloudy', icon: '⛅', risk: 'Low' },
        3:  { label: 'Overcast / Cloudy', icon: '☁️', risk: 'Low' },
        45: { label: 'Foggy Conditions', icon: '🌫️', risk: 'Caution (Fog)' },
        48: { label: 'Depositing Rime Fog', icon: '🌫️', risk: 'Caution (Fog)' },
        51: { label: 'Light Drizzle', icon: '🌦️', risk: 'Drizzle' },
        53: { label: 'Moderate Drizzle', icon: '🌦️', risk: 'Drizzle' },
        55: { label: 'Dense Drizzle', icon: '🌧️', risk: 'Wet Transit' },
        61: { label: 'Slight Rain', icon: '🌧️', risk: 'Rain Alert' },
        63: { label: 'Moderate Rain', icon: '🌧️', risk: 'Rain Alert' },
        65: { label: 'Heavy Rain', icon: '⛈️', risk: 'Heavy Rain Alert' },
        71: { label: 'Slight Snow', icon: '🌨️', risk: 'Snow' },
        73: { label: 'Moderate Snow', icon: '🌨️', risk: 'Snow' },
        75: { label: 'Heavy Snow', icon: '❄️', risk: 'Heavy Snow' },
        80: { label: 'Slight Showers', icon: '🌦️', risk: 'Rain Showers' },
        81: { label: 'Moderate Showers', icon: '🌧️', risk: 'Rain Showers' },
        82: { label: 'Violent Showers', icon: '⛈️', risk: 'Heavy Showers' },
        95: { label: 'Thunderstorm', icon: '⛈️', risk: 'Thunderstorm Alert' },
        96: { label: 'Thunderstorm with Hail', icon: '⛈️', risk: 'Severe Weather' },
        99: { label: 'Heavy Thunderstorm', icon: '⚡', risk: 'Severe Thunderstorm' }
    };

    function getWeatherInfo(code) {
        return WMO_CODES[code] || { label: 'Variable Weather', icon: '⛅', risk: 'Normal' };
    }

    function cleanLocationString(raw) {
        if (!raw || typeof raw !== 'string') return [];
        var s = raw.replace(/\b\d{6}\b/g, '').replace(/[\(\)\[\]\{\}]/g, ' ');
        s = s.replace(/\b(Pvt|Ltd|Limited|Unit|Site|Camp|Plant|Near|Opposite|Road|Nagar|Colony|Lane|Phase|Sector)\b/gi, ' ');
        var parts = s.split(/[,/\n\-]+/).map(function(p) { return p.trim(); }).filter(function(p) { return p.length >= 3; });
        var candidates = [];
        for (var i = parts.length - 1; i >= 0; i--) {
            var token = parts[i];
            if (!/^(india|up|mp|bihar|uttar pradesh|madhya pradesh)$/i.test(token)) {
                candidates.push(token);
            }
        }
        if (parts.length > 0) candidates.push(parts[0]);
        candidates.push(s.trim());
        return Array.from(new Set(candidates));
    }

    async function geocode(query) {
        var cleanQuery = query.trim();
        var cacheKey = 'geo_' + cleanQuery.toLowerCase();
        if (cache[cacheKey]) return cache[cacheKey];

        var candidates = cleanLocationString(cleanQuery);
        for (var i = 0; i < candidates.length; i++) {
            var cand = candidates[i];
            if (!cand || cand.length < 2) continue;
            try {
                var url = 'https://geocoding-api.open-meteo.com/v1/search?name=' + encodeURIComponent(cand) + '&count=1&language=en&format=json';
                var res = await fetch(url);
                if (!res.ok) continue;
                var data = await res.json();
                if (data && data.results && data.results.length > 0) {
                    var match = data.results[0];
                    cache[cacheKey] = match;
                    return match;
                }
            } catch (e) {
                // Continue to next candidate
            }
        }
        return null;
    }

    async function fetchForecast(lat, lon) {
        var cacheKey = 'w_' + lat.toFixed(2) + '_' + lon.toFixed(2);
        if (cache[cacheKey] && (Date.now() - cache[cacheKey].ts < 15 * 60 * 1000)) {
            return cache[cacheKey].data;
        }
        var url = 'https://api.open-meteo.com/v1/forecast?latitude=' + lat + '&longitude=' + lon +
                  '&current=temperature_2m,relative_humidity_2m,apparent_temperature,weather_code,wind_speed_10m' +
                  '&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max&timezone=auto';
        var res = await fetch(url);
        if (!res.ok) throw new Error('Weather fetch failed');
        var data = await res.json();
        cache[cacheKey] = { ts: Date.now(), data: data };
        return data;
    }

    function renderWidgetHtml(geo, weather) {
        var current = weather.current || {};
        var daily = weather.daily || {};
        var wCode = current.weather_code !== undefined ? current.weather_code : 0;
        var wInfo = getWeatherInfo(wCode);
        var temp = Math.round(current.temperature_2m || 0);
        var feels = Math.round(current.apparent_temperature || temp);
        var humidity = current.relative_humidity_2m || 0;
        var wind = Math.round(current.wind_speed_10m || 0);
        
        var rainProbToday = (daily.precipitation_probability_max && daily.precipitation_probability_max[0] !== undefined)
            ? daily.precipitation_probability_max[0] : 0;
        var rainProbTomorrow = (daily.precipitation_probability_max && daily.precipitation_probability_max[1] !== undefined)
            ? daily.precipitation_probability_max[1] : 0;

        var riskClass = 'dms-weather-risk-low';
        var riskIcon = 'bi-check-circle-fill';
        var riskText = 'Low Rain Risk (' + rainProbToday + '%)';

        if (rainProbToday > 50 || wCode >= 61) {
            riskClass = 'dms-weather-risk-high';
            riskIcon = 'bi-exclamation-triangle-fill';
            riskText = 'High Rain Alert (' + rainProbToday + '%) — Ensure Tarping';
        } else if (rainProbToday >= 20 || (wCode >= 51 && wCode <= 55)) {
            riskClass = 'dms-weather-risk-med';
            riskIcon = 'bi-cloud-rain-fill';
            riskText = 'Moderate Rain Risk (' + rainProbToday + '%)';
        }

        var locName = geo.name;
        if (geo.admin1 && geo.admin1 !== geo.name) locName += ', ' + geo.admin1;

        var forecastHtml = '';
        if (daily.time && daily.time.length >= 2) {
            var days = ['Today', 'Tomorrow'];
            for (var d = 0; d < 2; d++) {
                var dCode = daily.weather_code ? daily.weather_code[d] : 0;
                var dInfo = getWeatherInfo(dCode);
                var dMax = Math.round(daily.temperature_2m_max ? daily.temperature_2m_max[d] : 0);
                var dMin = Math.round(daily.temperature_2m_min ? daily.temperature_2m_min[d] : 0);
                var dRain = daily.precipitation_probability_max ? daily.precipitation_probability_max[d] : 0;

                forecastHtml += '<div class="dms-forecast-day">' +
                    '<div class="fw-semibold text-muted" style="font-size:0.7rem">' + days[d] + '</div>' +
                    '<div class="my-1">' + dInfo.icon + ' <span class="fw-bold">' + dMax + '°</span> <small class="text-muted">' + dMin + '°</small></div>' +
                    '<div class="' + (dRain > 40 ? 'text-danger fw-bold' : 'text-muted') + '" style="font-size:0.68rem"><i class="bi bi-droplet-fill text-info"></i> ' + dRain + '% Rain</div>' +
                '</div>';
            }
        }

        return '<div class="dms-weather-card">' +
            '<div class="d-flex justify-content-between align-items-start flex-wrap gap-2">' +
                '<div class="d-flex align-items-center gap-3">' +
                    '<div class="dms-weather-icon">' + wInfo.icon + '</div>' +
                    '<div>' +
                        '<div class="d-flex align-items-baseline gap-2">' +
                            '<span class="dms-weather-temp">' + temp + '°C</span>' +
                            '<span class="text-muted small">Feels ' + feels + '°C &bull; ' + wInfo.label + '</span>' +
                        '</div>' +
                        '<div class="small fw-semibold text-dark">' +
                            '<i class="bi bi-geo-alt-fill text-danger me-1"></i>' + locName +
                        '</div>' +
                    '</div>' +
                '</div>' +
                '<div class="d-flex align-items-center gap-2 flex-wrap">' +
                    '<div class="badge ' + riskClass + ' px-2 py-1" style="font-size:0.75rem">' +
                        '<i class="bi ' + riskIcon + ' me-1"></i>' + riskText +
                    '</div>' +
                    '<div class="small text-muted border-start ps-2 d-none d-sm-block">' +
                        '<div><i class="bi bi-wind me-1 text-secondary"></i>' + wind + ' km/h</div>' +
                        '<div><i class="bi bi-moisture me-1 text-info"></i>' + humidity + '% Hum.</div>' +
                    '</div>' +
                    '<div class="d-flex gap-1 ms-1 d-none d-md-flex">' +
                        forecastHtml +
                    '</div>' +
                '</div>' +
            '</div>' +
        '</div>';
    }

    async function updateWeatherForLocation(targetContainerId, locationText) {
        var container = document.getElementById(targetContainerId);
        if (!container) return;
        var clean = (locationText || '').trim();
        if (!clean || clean.length < 2) {
            container.innerHTML = '';
            container.classList.add('d-none');
            return;
        }

        container.classList.remove('d-none');
        container.innerHTML = '<div class="dms-weather-card py-2 text-muted small"><i class="bi bi-arrow-repeat spin me-1"></i> Fetching live delivery weather for ' + clean + '...</div>';

        try {
            var geo = await geocode(clean);
            if (!geo) {
                container.innerHTML = '<div class="dms-weather-card py-1 text-muted small" style="border-left-color:#94a3b8"><i class="bi bi-cloud-slash me-1"></i> Delivery weather unavailable for "' + clean + '"</div>';
                return;
            }
            var forecast = await fetchForecast(geo.latitude, geo.longitude);
            container.innerHTML = renderWidgetHtml(geo, forecast);
        } catch (err) {
            container.innerHTML = '<div class="dms-weather-card py-1 text-muted small" style="border-left-color:#f87171"><i class="bi bi-exclamation-circle text-danger me-1"></i> Could not load live weather. <button type="button" class="btn btn-link btn-sm p-0 ms-1" onclick="window.DMSWeather.load(\'' + targetContainerId + '\', \'' + clean.replace(/'/g, "\\'") + '\')">Retry</button></div>';
        }
    }

    var debounceTimers = {};

    return {
        load: function(containerId, locationText) {
            updateWeatherForLocation(containerId, locationText);
        },
        attach: function(containerId, inputElementOrSelector) {
            var inputEl = (typeof inputElementOrSelector === 'string')
                ? document.querySelector(inputElementOrSelector)
                : inputElementOrSelector;
            if (!inputEl) return;

            function trigger() {
                var val = inputEl.value;
                if (debounceTimers[containerId]) clearTimeout(debounceTimers[containerId]);
                debounceTimers[containerId] = setTimeout(function() {
                    updateWeatherForLocation(containerId, val);
                }, 400);
            }

            inputEl.addEventListener('input', trigger);
            inputEl.addEventListener('change', trigger);
            if (inputEl.value) trigger();
        }
    };
})();
}
</script>
