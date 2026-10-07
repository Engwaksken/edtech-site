(function () {
  'use strict';
  const token = document.querySelector('meta[name="csrf-token"]')?.content;
  if (!token) return;
  const unsafe = (method) => !['GET', 'HEAD', 'OPTIONS'].includes(String(method || 'GET').toUpperCase());
  const local = (url) => {
    try { return new URL(url, document.baseURI).origin === window.location.origin; }
    catch (_) { return false; }
  };
  function protectForm(form) {
    if (!(form instanceof HTMLFormElement) || !unsafe(form.method) || !local(form.action)) return;
    let field = form.querySelector('input[name="site_csrf_token"]');
    if (!field) {
      field = document.createElement('input');
      field.type = 'hidden';
      field.name = 'site_csrf_token';
      form.appendChild(field);
    }
    field.value = token;
  }
  document.addEventListener('submit', (event) => protectForm(event.target), true);
  const originalSubmit = HTMLFormElement.prototype.submit;
  HTMLFormElement.prototype.submit = function () {
    protectForm(this);
    return originalSubmit.call(this);
  };
  const originalFetch = window.fetch;
  if (originalFetch) {
    window.fetch = function (input, init) {
      const request = input instanceof Request ? input : null;
      const method = init?.method || request?.method || 'GET';
      const url = request ? request.url : String(input);
      if (unsafe(method) && local(url)) {
        const options = Object.assign({}, init);
        const headers = new Headers(options.headers || request?.headers);
        headers.set('X-CSRF-Token', token);
        options.headers = headers;
        return originalFetch.call(this, input, options);
      }
      return originalFetch.call(this, input, init);
    };
  }
  const originalOpen = XMLHttpRequest.prototype.open;
  const originalSend = XMLHttpRequest.prototype.send;
  const originalSetHeader = XMLHttpRequest.prototype.setRequestHeader;
  const requests = new WeakMap();
  XMLHttpRequest.prototype.open = function (method, url) {
    requests.set(this, { method, url });
    return originalOpen.apply(this, arguments);
  };
  XMLHttpRequest.prototype.setRequestHeader = function (name, value) {
    const request = requests.get(this);
    if (request && String(name).toLowerCase() === 'x-csrf-token') request.csrfHeaderSet = true;
    return originalSetHeader.apply(this, arguments);
  };
  XMLHttpRequest.prototype.send = function () {
    const request = requests.get(this);
    if (request && !request.csrfHeaderSet && unsafe(request.method) && local(request.url)) {
      this.setRequestHeader('X-CSRF-Token', token);
    }
    return originalSend.apply(this, arguments);
  };
}());
