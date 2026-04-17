/**
 * Shared IPMI monitoring helpers: sensor reading presentation + polling utilities.
 * Used by Sensors page, dashboard tiles, and other live views to stay consistent.
 */
(function(global) {
  'use strict';

  /**
   * @param {object} sensor  Sensor row from ipmi_helpers JSON
   * @param {boolean} unitIsF Display temperatures in Fahrenheit
   * @returns {string} HTML for reading cell (includes span.ipmi-reading--*)
   */
  function readingMarkup(sensor, unitIsF) {
    var LowerNR = parseFloat(sensor.LowerNR);
    var LowerC = parseFloat(sensor.LowerC);
    var LowerNC = parseFloat(sensor.LowerNC);
    var UpperNC = parseFloat(sensor.UpperNC);
    var UpperC = parseFloat(sensor.UpperC);
    var UpperNR = parseFloat(sensor.UpperNR);
    var color = 'green';
    var Units = '';
    var Reading;
    var level;

    if (sensor.Reading !== 'N/A') {
      Reading = parseFloat(sensor.Reading);
      if (sensor.Type === 'Voltage') {
        Units += ' ';
        if (Reading < LowerNC && Reading > UpperNC)
          color = 'orange';
        if (Reading < LowerC || Reading > UpperC)
          color = 'red';
      } else if (sensor.Type === 'Fan') {
        Units += ' ';
        if (Reading < LowerNC || Reading < LowerC || Reading < LowerNR)
          color = 'red';
      } else if (sensor.Type === 'Temperature') {
        if (Reading > UpperNC)
          color = 'orange';
        if (Reading > UpperC)
          color = 'red';
        if (unitIsF) {
          sensor.Units = 'F';
          Reading = Math.round(9 / 5 * Reading + 32);
        }
        Units += '&deg;';
      }
      Units += sensor.Units;
    } else {
      color = 'blue';
      if (sensor.Type === 'OEM Reserved') {
        Reading = sensor.Event;
        if (Reading === 'Low')
          color = 'green';
        if (Reading === 'Medium')
          color = 'orange';
        if (Reading === 'High')
          color = 'red';
      } else {
        Reading = sensor.Reading;
      }
    }

    if (color === 'green')
      level = 'nominal';
    else if (color === 'orange')
      level = 'warn';
    else if (color === 'red')
      level = 'critical';
    else
      level = 'na';

    return '<span class="ipmi-reading ipmi-reading--' + level + '">' + Reading + Units + '</span>';
  }

  /**
   * Call fn every intervalMs; skip execution while document is hidden (saves BMC + CPU).
   * When the tab becomes visible again, runs fn once after a short delay so readings catch up quickly.
   */
  function startIntervalWhenVisible(intervalMs, fn) {
    var flushTimer = null;

    function run() {
      try {
        if (!document.hidden)
          fn();
      } catch (e) { /* ignore */ }
    }

    function tick() {
      run();
      setTimeout(tick, intervalMs);
    }

    document.addEventListener('visibilitychange', function() {
      if (document.hidden)
        return;
      clearTimeout(flushTimer);
      flushTimer = setTimeout(run, 300);
    });

    setTimeout(tick, intervalMs);
  }

  global.IpmiMonitor = {
    readingMarkup: readingMarkup,
    startIntervalWhenVisible: startIntervalWhenVisible
  };
})(typeof window !== 'undefined' ? window : this);
