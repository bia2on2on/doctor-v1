/**
 * Behavioral tests for PATIENT-LOGIN-SLICE-1 in assets/js/cpms-patient-portal.js:
 *  - P2: national_id is absent from PROFILE_FIELD_KEYS and buildFormMarkup()
 *  - P4/P6: bindPatientOtpLogin() handles session_issued=true (reload) vs
 *           session_issued=false (clear reception-contact error) with Persian/Arabic digit normalization
 *  - P6: bindLinkRecord() validates 10-digit NID client-side, posts JSON {clinic_id, national_id}
 *        to config.link_record_path with X-WP-Nonce and credentials=same-origin, never leaks
 *        national_id in query parameters, clears NID input and reloads on patient_id > 0
 *
 * Run standalone: node clinic-practice-management/tests/js/patient-login-slice1.spec.js
 */
'use strict';

var fs = require('fs');
var path = require('path');
var vm = require('vm');

var pluginRoot = path.join(__dirname, '..', '..');
var portalSrc = fs.readFileSync(path.join(pluginRoot, 'assets/js/cpms-patient-portal.js'), 'utf8');

var failures = 0;
var checks = 0;

function assert(name, condition, message) {
  if (condition) {
    checks++;
    console.log('ok - ' + name);
  } else {
    failures++;
    console.error('FAIL - ' + name + ': ' + (message || 'assertion failed'));
  }
}

function makeNode(tag, attrs) {
  attrs = attrs || {};
  var listeners = {};
  var node = {
    tagName: (tag || 'DIV').toUpperCase(),
    attributes: Object.assign({}, attrs),
    children: [],
    value: attrs.value || '',
    textContent: '',
    innerHTML: '',
    hidden: !!attrs.hidden,
    disabled: false,
    focused: false,
    classList: {
      add: function () {},
      remove: function () {},
    },
    getAttribute: function (k) {
      return Object.prototype.hasOwnProperty.call(node.attributes, k) ? node.attributes[k] : null;
    },
    setAttribute: function (k, v) {
      node.attributes[k] = String(v);
    },
    removeAttribute: function (k) {
      delete node.attributes[k];
    },
    addEventListener: function (ev, fn) {
      listeners[ev] = listeners[ev] || [];
      listeners[ev].push(fn);
    },
    dispatchEvent: function (ev) {
      var list = listeners[ev.type] || [];
      for (var i = 0; i < list.length; i++) {
        list[i](ev);
      }
    },
    focus: function () {
      node.focused = true;
    },
    querySelector: function (sel) {
      return matchTree(node, sel)[0] || null;
    },
    querySelectorAll: function (sel) {
      return matchTree(node, sel);
    },
  };
  return node;
}

function matchSimple(node, sel) {
  var m = sel.match(/^\[data-role="([^"]+)"\]$/);
  if (m) {
    return node.getAttribute('data-role') === m[1];
  }
  var cl = sel.match(/^script\.([a-zA-Z0-9_-]+)$/);
  if (cl) {
    return node.tagName === 'SCRIPT' && (node.getAttribute('class') || '').split(/\s+/).indexOf(cl[1]) !== -1;
  }
  if (sel === 'p') {
    return node.tagName === 'P';
  }
  return false;
}

function matchTree(root, sel) {
  var out = [];
  function walk(cur) {
    for (var i = 0; i < cur.children.length; i++) {
      var c = cur.children[i];
      if (matchSimple(c, sel)) {
        out.push(c);
      }
      walk(c);
    }
  }
  walk(root);
  return out;
}

function runPortalWithDom(opts) {
  var root = makeNode('BODY');
  var reloadCount = 0;
  var fetchCalls = [];
  var nodes = opts.nodes || [];
  for (var i = 0; i < nodes.length; i++) {
    root.children.push(nodes[i]);
  }

  var documentStub = {
    readyState: 'complete',
    querySelector: function (sel) {
      return root.querySelector(sel);
    },
    querySelectorAll: function (sel) {
      return root.querySelectorAll(sel);
    },
    addEventListener: function () {},
  };

  var windowStub = {
    location: {
      origin: 'https://example.test',
      hash: '',
      reload: function () {
        reloadCount++;
      },
    },
    fetch: function (url, init) {
      fetchCalls.push({ url: String(url), init: init || {} });
      return opts.fetchImpl(String(url), init || {});
    },
    addEventListener: function () {},
  };

  var ctx = {
    window: windowStub,
    document: documentStub,
    URL: URL,
    encodeURIComponent: encodeURIComponent,
    parseInt: parseInt,
    String: String,
    Array: Array,
    Object: Object,
    JSON: JSON,
    Number: Number,
    Promise: Promise,
    RegExp: RegExp,
    setTimeout: setTimeout,
    clearTimeout: clearTimeout,
  };
  ctx.globalThis = ctx;

  // Expose PROFILE_FIELD_KEYS and buildFormMarkup for direct inspection in addition to init().
  var instrumented = portalSrc.replace(
    'init();',
    'init(); window.__TEST_HOOKS__ = { PROFILE_FIELD_KEYS: PROFILE_FIELD_KEYS, buildFormMarkup: buildFormMarkup, bindLinkRecord: bindLinkRecord };'
  );
  vm.runInNewContext(instrumented, ctx, { filename: 'cpms-patient-portal.js' });

  return {
    hooks: windowStub.__TEST_HOOKS__,
    fetchCalls: fetchCalls,
    getReloadCount: function () { return reloadCount; },
  };
}

function flushPromises() {
  return new Promise(function (resolve) {
    setTimeout(resolve, 15);
  });
}

(async function main() {
  // ---- Test 1 (P2): PROFILE_FIELD_KEYS & buildFormMarkup omit national_id ----
  var t1 = runPortalWithDom({
    nodes: [],
    fetchImpl: function () { return Promise.reject(new Error('unexpected fetch')); },
  });
  assert(
    'p2_profile_field_keys_excludes_national_id',
    t1.hooks.PROFILE_FIELD_KEYS.indexOf('national_id') === -1,
    'PROFILE_FIELD_KEYS must not contain national_id'
  );
  var formHtml = t1.hooks.buildFormMarkup();
  assert(
    'p2_build_form_markup_excludes_national_id',
    formHtml.indexOf('national_id') === -1 && formHtml.indexOf('کد ملی') === -1,
    'buildFormMarkup must not render national_id'
  );

  // ---- Test 2 (P4 + P6): Guest OTP login handles session_issued=false vs true ----
  var authCfgScript = makeNode('SCRIPT', { class: 'cpms-patient-portal__auth-config' });
  authCfgScript.textContent = JSON.stringify({
    rest_root: 'https://example.test/wp-json/clinic/v1',
    otp_request_path: '/otp/request',
    otp_verify_path: '/otp/verify',
  });
  var otpForm = makeNode('DIV', { 'data-role': 'patient-otp-login' });
  var mobileIn = makeNode('INPUT', { 'data-role': 'patient-otp-mobile' });
  var codeStep = makeNode('DIV', { 'data-role': 'patient-otp-code-step', hidden: true });
  var codeIn = makeNode('INPUT', { 'data-role': 'patient-otp-code' });
  var reqBtn = makeNode('BUTTON', { 'data-role': 'patient-otp-request' });
  var verBtn = makeNode('BUTTON', { 'data-role': 'patient-otp-verify', hidden: true });
  var statusBox = makeNode('DIV', { 'data-role': 'patient-otp-status', hidden: true });
  otpForm.children.push(mobileIn, codeStep, codeIn, reqBtn, verBtn, statusBox);

  var verifyMode = 'no_account';
  var t2 = runPortalWithDom({
    nodes: [authCfgScript, otpForm],
    fetchImpl: function (url) {
      if (url.endsWith('/otp/request')) {
        return Promise.resolve({
          ok: true,
          status: 200,
          json: function () { return Promise.resolve({ data: { sms_sent: true } }); },
        });
      }
      if (url.endsWith('/otp/verify')) {
        if (verifyMode === 'no_account') {
          return Promise.resolve({
            ok: true,
            status: 200,
            json: function () {
              return Promise.resolve({ data: { user_id: 0, patient_links: [], is_new_user: false, session_issued: false } });
            },
          });
        }
        return Promise.resolve({
          ok: true,
          status: 200,
          json: function () {
            return Promise.resolve({ data: { user_id: 42, patient_links: [], is_new_user: false, session_issued: true } });
          },
        });
      }
      return Promise.reject(new Error('unexpected url ' + url));
    },
  });

  mobileIn.value = '۰۹۱۲۳۴۵۶۷۸۹';
  reqBtn.dispatchEvent({ type: 'click', preventDefault: function () {} });
  await flushPromises();
  assert(
    'p6_otp_request_normalizes_persian_digits',
    t2.fetchCalls.length === 1 && JSON.parse(t2.fetchCalls[0].init.body).mobile === '09123456789' && JSON.parse(t2.fetchCalls[0].init.body).purpose === 'login',
    'OTP request must normalize Persian digits and pass purpose=login'
  );
  assert('p6_otp_code_step_visible', codeStep.hidden === false, 'code step must reveal after sms_sent=true');

  codeIn.value = '۱۲۳۴۵۶';
  verBtn.dispatchEvent({ type: 'click', preventDefault: function () {} });
  await flushPromises();
  assert(
    'p6_otp_no_account_copy_is_helpful_not_invalid_code',
    t2.getReloadCount() === 0 &&
      statusBox.textContent.indexOf('پذیرش') !== -1 &&
      statusBox.textContent.indexOf('کد معتبر نیست') === -1,
    'session_issued=false must show reception guidance, not invalid-code error'
  );

  verifyMode = 'session';
  verBtn.dispatchEvent({ type: 'click', preventDefault: function () {} });
  await flushPromises();
  assert(
    'p4_otp_session_issued_reloads_portal',
    t2.getReloadCount() === 1,
    'session_issued=true must reload portal'
  );

  // ---- Test 3 (P6): bindLinkRecord() validation, JSON POST, no query NID, reload ----
  var portalCfg = makeNode('SCRIPT', { class: 'cpms-patient-portal__config' });
  portalCfg.textContent = JSON.stringify({
    rest_root: 'https://example.test/wp-json/clinic/v1',
    nonce: 'nonce-abc-123',
    cancel_path: '/appointments/{id}/cancel',
    link_record_path: '/patient/link-record',
  });
  var lrSection = makeNode('SECTION', { 'data-role': 'link-record-section' });
  var lrForm = makeNode('FORM', { 'data-role': 'link-record-form' });
  var lrClinic = makeNode('SELECT', { 'data-role': 'link-record-clinic' });
  lrClinic.value = '7';
  var lrNid = makeNode('INPUT', { 'data-role': 'link-record-national-id' });
  var lrSubmit = makeNode('BUTTON', { 'data-role': 'link-record-submit' });
  lrSubmit.textContent = 'تأیید و ورود به پرونده';
  var lrErr = makeNode('DIV', { 'data-role': 'link-record-error', hidden: true });
  lrErr.children.push(makeNode('P'));
  var lrOk = makeNode('DIV', { 'data-role': 'link-record-success', hidden: true });
  lrOk.children.push(makeNode('P'));
  lrForm.children.push(lrClinic, lrNid, lrSubmit, lrErr, lrOk);
  lrSection.children.push(lrForm);

  var t3 = runPortalWithDom({
    nodes: [portalCfg, lrSection],
    fetchImpl: function () {
      return Promise.resolve({
        ok: true,
        status: 200,
        json: function () {
          return Promise.resolve({ data: { patient_id: 99, clinic_id: 7 } });
        },
      });
    },
  });

  // Invalid short NID must be blocked client-side before fetch.
  lrNid.value = '12345';
  lrForm.dispatchEvent({ type: 'submit', preventDefault: function () {} });
  await flushPromises();
  assert(
    'p6_link_record_blocks_short_nid_without_network',
    t3.fetchCalls.length === 0 && lrErr.hidden === false && lrNid.focused === true,
    'short NID must fail client-side without fetch'
  );

  // Valid 10-digit Persian-digit NID sends POST JSON {clinic_id, national_id}, no query string, no mobile/code.
  lrNid.value = '۰۰۱۰۰۰۰۰۰۳';
  lrForm.dispatchEvent({ type: 'submit', preventDefault: function () {} });
  await flushPromises();
  assert('p6_link_record_posts_once', t3.fetchCalls.length === 1, 'valid submit must call fetch once');
  var req = t3.fetchCalls[0];
  var reqBody = JSON.parse(req.init.body || '{}');
  assert(
    'p6_link_record_url_method_headers_and_body',
    req.url === 'https://example.test/wp-json/clinic/v1/patient/link-record' &&
      req.url.indexOf('national_id') === -1 &&
      req.url.indexOf('0010000003') === -1 &&
      req.init.method === 'POST' &&
      req.init.credentials === 'same-origin' &&
      req.init.headers['X-WP-Nonce'] === 'nonce-abc-123' &&
      reqBody.clinic_id === 7 &&
      reqBody.national_id === '0010000003' &&
      !Object.prototype.hasOwnProperty.call(reqBody, 'mobile') &&
      !Object.prototype.hasOwnProperty.call(reqBody, 'code'),
    'link-record request must POST clean JSON {clinic_id, national_id} with nonce and no query NID'
  );
  assert(
    'p6_link_record_clears_nid_and_reloads',
    lrNid.value === '' && t3.getReloadCount() === 1,
    'successful link-record must clear NID input and reload'
  );

  if (failures > 0) {
    console.error('FAILED checks: ' + failures);
    process.exit(1);
  }
  console.log('ALL PASSED (' + checks + ' checks)');
})();
