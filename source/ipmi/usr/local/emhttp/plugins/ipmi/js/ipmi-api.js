function ipmiResponseText(response) {
  if (!response) return 'Request failed.';
  if (response.message) return response.message;
  if (response.errors && response.errors.length) return response.errors.join("\n");
  return 'Request failed.';
}

function ipmiResolveCsrfToken() {
  if (window.IPMI_CSRF_TOKEN) return window.IPMI_CSRF_TOKEN;
  if (window.csrf_token) return window.csrf_token;

  var input = document.querySelector('input[name="csrf_token"]');
  return input ? input.value : '';
}

function ipmiFlashBanner(text, tone) {
  tone = tone || 'success';
  var shell = document.querySelector('.ipmi-shell');
  if (!shell || !text) return;

  var el = document.createElement('div');
  el.className = 'ipmi-flash ipmi-flash--' + tone;
  el.setAttribute('role', 'status');
  el.textContent = text;
  shell.insertBefore(el, shell.firstChild);
  setTimeout(function() {
    if (el.parentNode) el.parentNode.removeChild(el);
  }, 6000);
}

function ipmiSubmitToolDownload(tool) {
  var form = document.createElement('form');
  form.method = 'POST';
  form.action = '/plugins/ipmi/include/ipmi_plugin_tools.php';
  form.target = '_blank';
  form.style.display = 'none';

  var csrf = document.createElement('input');
  csrf.type = 'hidden';
  csrf.name = 'csrf_token';
  csrf.value = ipmiResolveCsrfToken();
  form.appendChild(csrf);

  var t = document.createElement('input');
  t.type = 'hidden';
  t.name = 'tool';
  t.value = tool;
  form.appendChild(t);

  document.body.appendChild(form);
  form.submit();
  document.body.removeChild(form);
}

/**
 * Find top-level emhttp tab controls for the current page (Unraid 7+ `role="tab"`
 * buttons, or legacy `.tabs` radio rows). Match is applied to trimmed label text.
 */
function ipmiFindMainTabControls(labelMatch) {
  labelMatch = labelMatch || /.*/;
  var $modern = $('.tabs-container button[role="tab"]').filter(function() {
    return labelMatch.test((($(this).text() || '').trim()).toLowerCase());
  });
  if ($modern.length)
    return $modern;
  return $('.tabs .tab input[type="radio"][id^="tab"]').filter(function() {
    var t = ($('label[for="' + this.id + '"]').text() || '').trim().toLowerCase();
    return labelMatch.test(t);
  });
}

function ipmiToggleMainTabControls(labelMatch, visible) {
  ipmiFindMainTabControls(labelMatch).each(function() {
    var $n = $(this);
    if ($n.is('button'))
      $n.toggle(visible);
    else
      $n.closest('.tab').toggle(visible);
  });
}

function ipmiOnMainTabClick(labelMatch, handler) {
  ipmiFindMainTabControls(labelMatch).on('click', handler);
}

/**
 * Mirror a hidden enable/disable <select> (values "enable" / "disable") with Unraid's
 * jquery.switchButton so existing form posts and .change() handlers keep working.
 */
function ipmiWireEnableSelectToggle(checkboxSelector, selectSelector, switchOptions) {
  if (typeof window.jQuery === 'undefined')
    return;
  var $ = window.jQuery;
  if (typeof $.fn.switchButton !== 'function')
    return;

  var $cb = $(checkboxSelector);
  var $sel = $(selectSelector);
  if (!$cb.length || !$sel.length)
    return;

  var opts = $.extend({
    labels_placement: 'left',
    on_label: 'Yes',
    off_label: 'No',
    checked: $sel.val() === 'enable'
  }, switchOptions || {});

  $cb.switchButton(opts);

  var lock = false;

  $cb.off('change.ipmiEnableToggle').on('change.ipmiEnableToggle', function() {
    if (lock)
      return;
    lock = true;
    var next = $cb[0].checked ? 'enable' : 'disable';
    if ($sel.val() !== next)
      $sel.val(next).trigger('change');
    lock = false;
  });

  $sel.off('change.ipmiEnableToggle').on('change.ipmiEnableToggle', function() {
    if (lock)
      return;
    var on = $sel.val() === 'enable';
    if (!!$cb[0].checked === on)
      return;
    lock = true;
    $cb[0].checked = on;
    $cb.trigger('change');
    lock = false;
  });
}

function ipmiBindGlobalControls() {
  $(document).on('click', '#ipmi-diag-export', function(e) {
    e.preventDefault();
    ipmiSubmitToolDownload('diag_download');
  });
}

function ipmiPost(path, payload, onSuccess, onError, options) {
  options = options || {};
  var csrfToken = ipmiResolveCsrfToken();
  var requestPayload;
  var $btn = options.button ? $(options.button) : $();

  if ($btn.length)
    $btn.prop('disabled', true).addClass('is-busy');

  if ($.isArray(payload)) {
    requestPayload = payload.slice(0);
    var hasCsrf = requestPayload.some(function(entry) {
      return entry && entry.name === 'csrf_token';
    });
    if (!hasCsrf)
      requestPayload.push({ name: 'csrf_token', value: csrfToken });
  } else {
    requestPayload = $.extend({}, payload || {}, {
      csrf_token: csrfToken
    });
  }

  return $.ajax({
    url: path,
    method: 'POST',
    dataType: 'json',
    data: requestPayload
  })
    .always(function() {
      if ($btn.length)
        $btn.prop('disabled', false).removeClass('is-busy');
    })
    .done(function(response) {
      if (response && response.ok) {
        if (options.showSuccessBanner) {
          var msg = options.successMessage || response.message || 'Saved.';
          ipmiFlashBanner(msg, 'success');
        }
        if (onSuccess) onSuccess(response.data || {}, response);
        return;
      }

      if (onError) {
        onError(response || {});
        return;
      }

      if (options.showErrorBanner)
        ipmiFlashBanner(ipmiResponseText(response), 'error');
      else if (window.swal)
        swal({ title: 'IPMI', text: ipmiResponseText(response), type: 'error' });
    })
    .fail(function(xhr) {
      var response = xhr.responseJSON || {
        message: xhr.statusText || 'Request failed.'
      };

      if (onError) {
        onError(response);
        return;
      }

      if (options.showErrorBanner)
        ipmiFlashBanner(ipmiResponseText(response), 'error');
      else if (window.swal)
        swal({ title: 'IPMI', text: ipmiResponseText(response), type: 'error' });
    });
}

(function bindIpmiGlobalWhenReady() {
  function run() {
    if (typeof window.jQuery !== 'undefined')
      window.jQuery(ipmiBindGlobalControls);
  }

  if (document.readyState === 'loading')
    document.addEventListener('DOMContentLoaded', run);
  else
    run();
})();
