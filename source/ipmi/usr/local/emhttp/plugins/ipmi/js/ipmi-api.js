function ipmiResponseText(response) {
  if (!response) return 'Request failed.';
  if (response.message) return response.message;
  if (response.errors && response.errors.length) return response.errors.join("\n");
  return 'Request failed.';
}

function ipmiPost(path, payload, onSuccess, onError) {
  var requestPayload;
  if ($.isArray(payload)) {
    requestPayload = payload.slice(0);
    var hasCsrf = requestPayload.some(function(entry) {
      return entry && entry.name === 'csrf_token';
    });
    if (!hasCsrf) {
      requestPayload.push({name: 'csrf_token', value: window.IPMI_CSRF_TOKEN || ''});
    }
  } else {
    requestPayload = $.extend({}, payload || {}, {
      csrf_token: window.IPMI_CSRF_TOKEN || ''
    });
  }

  return $.ajax({
    url: path,
    method: 'POST',
    dataType: 'json',
    data: requestPayload
  })
  .done(function(response) {
    if (response && response.ok) {
      if (onSuccess) onSuccess(response.data || {}, response);
      return;
    }

    if (onError) {
      onError(response || {});
      return;
    }

    if (window.swal) {
      swal({title: 'IPMI', text: ipmiResponseText(response), type: 'error'});
    }
  })
  .fail(function(xhr) {
    var response = xhr.responseJSON || {
      message: xhr.statusText || 'Request failed.'
    };

    if (onError) {
      onError(response);
      return;
    }

    if (window.swal) {
      swal({title: 'IPMI', text: ipmiResponseText(response), type: 'error'});
    }
  });
}
