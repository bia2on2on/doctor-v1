/**
 * Behavioral tests for PATIENT-REGISTRATION-SLICE-2 (two-step split) in:
 *  - assets/js/cpms-patient-portal.js (`bindPatientRegistration`, `bindCompleteRegistration`)
 *  - assets/js/cpms-public-booking.js (`otp-register` / `regOtpRequest` / `regOtpVerify` / `regSubmit`)
 *
 * Run standalone: node clinic-practice-management/tests/js/patient-registration-slice2.spec.js
 */
'use strict';

var fs = require('fs');
var path = require('path');
var vm = require('vm');

var pluginRoot = path.join(__dirname, '..', '..');
var portalSrc = fs.readFileSync(path.join(pluginRoot, 'assets/js/cpms-patient-portal.js'), 'utf8');
var bookingSrc = fs.readFileSync(path.join(pluginRoot, 'assets/js/cpms-public-booking.js'), 'utf8');

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
    parentNode: null,
    value: attrs.value || '',
    textContent: '',
    innerHTML: '',
    hidden: !!attrs.hidden,
    disabled: false,
    readOnly: false,
    focused: false,
    options: [],
    selectedIndex: 0,
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
    appendChild: function (child) {
      child.parentNode = node;
      node.children.push(child);
      return child;
    },
    contains: function (other) {
      if (other === node) {
        return true;
      }
      for (var i = 0; i < node.children.length; i++) {
        if (node.children[i].contains(other)) {
          return true;
        }
      }
      return false;
    },
    matches: function (sel) {
      return matchSimple(node, sel);
    },
    closest: function (sel) {
      var cur = node;
      while (cur) {
        if (matchSimple(cur, sel)) {
          return cur;
        }
        cur = cur.parentNode;
      }
      return null;
    },
    addEventListener: function (ev, fn) {
      listeners[ev] = listeners[ev] || [];
      listeners[ev].push(fn);
    },
    dispatchEvent: function (ev) {
      if (!ev.target) {
        ev.target = node;
      }
      var cur = node;
      while (cur) {
        var list = (cur._listeners && cur._listeners[ev.type]) || [];
        for (var i = 0; i < list.length; i++) {
          list[i](ev);
        }
        cur = cur.parentNode;
      }
    },
    _listeners: listeners,
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
  if (!node || !node.getAttribute) {
    return false;
  }
  var parts = sel.split(',').map(function (s) { return s.trim(); });
  if (parts.length > 1) {
    for (var p = 0; p < parts.length; p++) {
      if (matchSimple(node, parts[p])) {
        return true;
      }
    }
    return false;
  }
  var mAttr = sel.match(/^\[([a-zA-Z0-9_-]+)="([^"]+)"\]$/);
  if (mAttr) {
    return node.getAttribute(mAttr[1]) === mAttr[2];
  }
  var mHasAttr = sel.match(/^\[([a-zA-Z0-9_-]+)\]$/);
  if (mHasAttr) {
    return node.getAttribute(mHasAttr[1]) !== null;
  }
  var cl = sel.match(/^(?:([a-zA-Z]+))?\.([a-zA-Z0-9_-]+)$/);
  if (cl) {
    var tagOk = !cl[1] || node.tagName === cl[1].toUpperCase();
    return tagOk && (node.getAttribute('class') || '').split(/\s+/).indexOf(cl[2]) !== -1;
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

function flushPromises() {
  return new Promise(function (resolve) {
    setImmediate(resolve);
  });
}

function runScriptWithDom(src, opts) {
  var body = makeNode('BODY');
  var reloadCount = 0;
  var fetchCalls = [];
  var sessionStore = {};
  (opts.nodes || []).forEach(function (n) {
    body.appendChild(n);
  });

  var documentStub = {
    readyState: 'complete',
    querySelector: function (sel) {
      return body.querySelector(sel);
    },
    querySelectorAll: function (sel) {
      return body.querySelectorAll(sel);
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
      assign: function () {},
    },
    addEventListener: function () {},
    sessionStorage: {
      getItem: function (k) { return Object.prototype.hasOwnProperty.call(sessionStore, k) ? sessionStore[k] : null; },
      setItem: function (k, v) { sessionStore[k] = String(v); },
      removeItem: function (k) { delete sessionStore[k]; },
    },
    fetch: function (url, init) {
      fetchCalls.push({ url: url, init: init || {} });
      return opts.fetchImpl(url, init || {});
    },
    Promise: Promise,
    setTimeout: setTimeout,
    clearTimeout: clearTimeout,
    setInterval: setInterval,
    clearInterval: clearInterval,
    CPMS_PORTAL_TEST_MODE: true,
  };

  vm.runInNewContext(src, {
    window: windowStub,
    document: documentStub,
    console: console,
    URL: URL,
    JSON: JSON,
    Promise: Promise,
    Object: Object,
    Array: Array,
    String: String,
    Number: Number,
    Math: Math,
    isFinite: isFinite,
    parseInt: parseInt,
    encodeURIComponent: encodeURIComponent,
    setTimeout: setTimeout,
    clearTimeout: clearTimeout,
    setInterval: setInterval,
    clearInterval: clearInterval,
  });

  return {
    fetchCalls: fetchCalls,
    getReloadCount: function () { return reloadCount; },
    sessionStore: sessionStore,
  };
}

async function runTests() {
  // ---- Test 1: Portal guest two-step registration ----
  var authCfg = makeNode('SCRIPT', { class: 'cpms-patient-portal__auth-config' });
  authCfg.textContent = JSON.stringify({
    rest_root: 'https://example.test/wp-json/clinic/v1',
    otp_request_path: '/otp/request',
    otp_verify_path: '/otp/verify',
    register_path: '/patient/register',
  });

  var regBox = makeNode('DIV', { 'data-role': 'patient-register-form' });
  var regClinic = makeNode('SELECT', { 'data-role': 'register-clinic' });
  regClinic.value = '1';
  var regMobile = makeNode('INPUT', { 'data-role': 'register-mobile' });
  var regReqBtn = makeNode('BUTTON', { 'data-role': 'register-otp-request' });
  var regCodeStep = makeNode('DIV', { 'data-role': 'register-code-step', hidden: true });
  var regCode = makeNode('INPUT', { 'data-role': 'register-code' });
  var regVerifyBtn = makeNode('BUTTON', { 'data-role': 'register-otp-verify' });
  var regDetailsStep = makeNode('DIV', { 'data-role': 'register-details-step', hidden: true });
  var regGiven = makeNode('INPUT', { 'data-role': 'register-given-name' });
  var regFamily = makeNode('INPUT', { 'data-role': 'register-family-name' });
  var regNid = makeNode('INPUT', { 'data-role': 'register-national-id' });
  var regBirth = makeNode('INPUT', { 'data-role': 'register-birth-date' });
  var regSubmitBtn = makeNode('BUTTON', { 'data-role': 'register-submit' });
  var regStatus = makeNode('DIV', { 'data-role': 'register-status', hidden: true });

  regCodeStep.appendChild(regCode);
  regCodeStep.appendChild(regVerifyBtn);
  [regGiven, regFamily, regNid, regBirth, regSubmitBtn].forEach(function (el) {
    regDetailsStep.appendChild(el);
  });
  [regClinic, regMobile, regReqBtn, regCodeStep, regDetailsStep, regStatus].forEach(function (el) {
    regBox.appendChild(el);
  });

  var regOutcome = 'fail403';
  var portal = runScriptWithDom(portalSrc, {
    nodes: [authCfg, regBox],
    fetchImpl: function (url) {
      if (url.endsWith('/otp/request')) {
        return Promise.resolve({
          ok: true,
          status: 200,
          json: function () { return Promise.resolve({ data: { sms_sent: true } }); },
        });
      }
      if (url.endsWith('/otp/verify')) {
        return Promise.resolve({
          ok: true,
          status: 200,
          json: function () {
            return Promise.resolve({
              data: {
                user_id: 99,
                session_issued: true,
                pending_registration: true,
                clinic_id: 1,
                rest_nonce: 'step1_nonce_abc',
              },
            });
          },
        });
      }
      if (url.endsWith('/patient/register')) {
        if (regOutcome === 'fail403') {
          return Promise.resolve({
            ok: false,
            status: 403,
            json: function () {
              return Promise.resolve({ code: 'CLINIC_PATIENT_PROOF_FAILED', message: 'تأیید هویت انجام نشد.' });
            },
          });
        }
        return Promise.resolve({
          ok: true,
          status: 200,
          json: function () { return Promise.resolve({ data: { registered: true, session_issued: true } }); },
        });
      }
      return Promise.reject(new Error('unexpected url: ' + url));
    },
  });

  regMobile.value = '۰۹۱۲۵۵۵۰۱۰۱';
  regReqBtn.dispatchEvent({ type: 'click', preventDefault: function () {} });
  await flushPromises();

  var otpReqPayload = JSON.parse(portal.fetchCalls[0].init.body);
  assert(
    'portal_reg_otp_request_verify_mobile',
    portal.fetchCalls.length === 1 &&
      otpReqPayload.purpose === 'verify_mobile' &&
      otpReqPayload.clinic_id === 1 &&
      otpReqPayload.mobile === '09125550101',
    'portal reg OTP request must send verify_mobile + clinic_id + normalized mobile'
  );
  assert(
    'portal_reg_reveals_code_step_only',
    regCodeStep.hidden === false && regDetailsStep.hidden === true,
    'OTP request must reveal code step ONLY while keeping profile details hidden'
  );

  // Step 1b: verify OTP before profile inputs appear.
  regCode.value = '۱۲۳۴۵۶';
  regVerifyBtn.dispatchEvent({ type: 'click', preventDefault: function () {} });
  await flushPromises();

  var otpVerifyPayload = JSON.parse(portal.fetchCalls[1].init.body);
  assert(
    'portal_reg_otp_verify_sends_verify_mobile_and_clinic',
    portal.fetchCalls.length === 2 &&
      otpVerifyPayload.purpose === 'verify_mobile' &&
      otpVerifyPayload.clinic_id === 1 &&
      otpVerifyPayload.code === '123456' &&
      regCodeStep.hidden === true &&
      regDetailsStep.hidden === false,
    'Step 1 otp/verify must verify OTP with clinic_id and reveal profile step'
  );

  // Step 2: submit profile + NID with Step-1 nonce and NO code in body.
  regGiven.value = 'سارا';
  regFamily.value = 'محمدی';
  regNid.value = '۰۴۹۹۳۷۰۸۹۹';
  regBirth.value = '۱۹۹۳-۰۷-۲۱';
  regBox.dispatchEvent({ type: 'submit', preventDefault: function () {} });
  await flushPromises();

  var regCall = portal.fetchCalls[2];
  var regPayload = JSON.parse(regCall.init.body);
  assert(
    'portal_reg_post_json_no_query_pii_no_code',
    regCall.url === 'https://example.test/wp-json/clinic/v1/patient/register' &&
      regCall.init.credentials === 'same-origin' &&
      regCall.init.headers['X-WP-Nonce'] === 'step1_nonce_abc' &&
      regPayload.clinic_id === 1 &&
      regPayload.national_id === '0499370899' &&
      regPayload.birth_date === '1993-07-21' &&
      !Object.prototype.hasOwnProperty.call(regPayload, 'code'),
    'Step 2 register must POST JSON with X-WP-Nonce, normalized fields, and NO code'
  );
  assert(
    'portal_reg_403_shows_uniform_error_no_reload',
    portal.getReloadCount() === 0 &&
      regStatus.textContent === 'تأیید هویت انجام نشد.' &&
      regStatus.textContent.indexOf('0499370899') === -1,
    'uniform 403 error must be displayed without reload or NID leak'
  );

  regOutcome = 'ok';
  regBox.dispatchEvent({ type: 'submit', preventDefault: function () {} });
  await flushPromises();
  assert('portal_reg_success_reloads', portal.getReloadCount() === 1, 'registered=true must reload portal');

  // ---- Test 2: Public booking inline two-step registration ----
  var pbRoot = makeNode('DIV', { class: 'cpms-public-booking', 'data-clinic-id': '1' });
  var pbCfg = makeNode('SCRIPT', { class: 'cpms-public-booking__config' });
  pbCfg.textContent = JSON.stringify({
    clinic_id: 1,
    rest_root: 'https://example.test/wp-json/clinic/v1',
    availability_path: '/availability',
    quote_path: '/booking/quote',
    otp_request_path: '/otp/request',
    otp_verify_path: '/otp/verify',
    register_path: '/patient/register',
  });
  var pbPanel = makeNode('DIV', { 'data-role': 'panel' });
  var pbClinicians = makeNode('DIV', { 'data-role': 'clinicians' });
  var pbDays = makeNode('DIV', { 'data-role': 'days' });
  var pbDetail = makeNode('DIV', { 'data-role': 'detail' });
  var pbAuth = makeNode('DIV', { 'data-role': 'otp-step', class: 'cpms-public-booking__auth' });
  var pbContinue = makeNode('BUTTON', { 'data-role': 'continue-auth' });
  var pbStepMobile = makeNode('DIV', { 'data-auth-step': 'otp-mobile' });
  var pbStepCode = makeNode('DIV', { 'data-auth-step': 'otp-code', hidden: true });
  var pbStepReg = makeNode('DIV', { 'data-auth-step': 'otp-register', 'data-register-path': '/patient/register', hidden: true });
  var pbShowRegBtn = makeNode('BUTTON', { 'data-auth-action': 'show-register' });
  var pbShowLoginBtn = makeNode('BUTTON', { 'data-auth-action': 'show-login' });
  var pbRegOtpBtn = makeNode('BUTTON', { 'data-auth-action': 'reg-otp-request' });
  var pbRegCodeStep = makeNode('DIV', { 'data-role': 'reg-code-step', hidden: true });
  var pbRegVerifyBtn = makeNode('BUTTON', { 'data-auth-action': 'reg-otp-verify' });
  var pbRegSubmitBtn = makeNode('BUTTON', { 'data-auth-action': 'reg-submit' });
  var pbRegMobile = makeNode('INPUT', { 'data-role': 'reg-mobile' });
  var pbRegDetailsWrap = makeNode('DIV', { 'data-role': 'reg-details', hidden: true });
  var pbRegCode = makeNode('INPUT', { 'data-role': 'reg-code' });
  var pbRegGiven = makeNode('INPUT', { 'data-role': 'reg-given-name' });
  var pbRegFamily = makeNode('INPUT', { 'data-role': 'reg-family-name' });
  var pbRegNid = makeNode('INPUT', { 'data-role': 'reg-national-id' });
  var pbRegBirth = makeNode('INPUT', { 'data-role': 'reg-birth-date' });
  var pbAuthStatus = makeNode('P', { 'data-role': 'auth-status', hidden: true });

  [
    pbStepMobile, pbStepCode,
    pbStepReg, pbContinue, pbAuthStatus,
  ].forEach(function (n) { pbAuth.appendChild(n); });
  pbStepMobile.appendChild(pbShowRegBtn);
  [pbRegCode, pbRegVerifyBtn].forEach(function (n) { pbRegCodeStep.appendChild(n); });
  [pbRegGiven, pbRegFamily, pbRegNid, pbRegBirth, pbRegSubmitBtn].forEach(function (n) {
    pbRegDetailsWrap.appendChild(n);
  });
  [
    pbRegMobile, pbRegOtpBtn, pbRegCodeStep, pbRegDetailsWrap, pbShowLoginBtn,
  ].forEach(function (n) { pbStepReg.appendChild(n); });
  [pbCfg, pbPanel, pbClinicians, pbDays, pbDetail, pbAuth].forEach(function (n) { pbRoot.appendChild(n); });

  var pb = runScriptWithDom(bookingSrc, {
    nodes: [pbRoot],
    fetchImpl: function (url) {
      if (url.endsWith('/otp/request')) {
        return Promise.resolve({
          ok: true,
          status: 200,
          json: function () { return Promise.resolve({ data: { sms_sent: true } }); },
        });
      }
      if (url.endsWith('/otp/verify')) {
        return Promise.resolve({
          ok: true,
          status: 200,
          json: function () {
            return Promise.resolve({
              data: {
                user_id: 102,
                session_issued: true,
                pending_registration: true,
                clinic_id: 1,
                rest_nonce: 'pb_step1_nonce',
              },
            });
          },
        });
      }
      if (url.endsWith('/patient/register')) {
        return Promise.resolve({
          ok: true,
          status: 200,
          json: function () { return Promise.resolve({ data: { registered: true, session_issued: true } }); },
        });
      }
      return Promise.reject(new Error('unexpected url: ' + url));
    },
  });

  // Switch from login step to register step and back.
  pbShowRegBtn.dispatchEvent({ type: 'click', target: pbShowRegBtn, preventDefault: function () {} });
  assert('booking_show_register_reveals_step', pbStepReg.hidden === false && pbStepMobile.hidden === true, 'otp-register step must show');
  pbShowLoginBtn.dispatchEvent({ type: 'click', target: pbShowLoginBtn, preventDefault: function () {} });
  assert('booking_show_login_returns_step', pbStepMobile.hidden === false && pbStepReg.hidden === true, 'otp-mobile step must return');

  // Step 1a: request verify_mobile OTP -> reveals reg-code-step ONLY, reg-details stays hidden.
  pbShowRegBtn.dispatchEvent({ type: 'click', target: pbShowRegBtn, preventDefault: function () {} });
  pbRegMobile.value = '۰۹۱۲۵۵۵۰۱۰۲';
  pbRegOtpBtn.dispatchEvent({ type: 'click', target: pbRegOtpBtn, preventDefault: function () {} });
  await flushPromises();

  var pbOtpPayload = JSON.parse(pb.fetchCalls[0].init.body);
  assert(
    'booking_reg_otp_request_verify_mobile',
    pb.fetchCalls.length === 1 &&
      pbOtpPayload.purpose === 'verify_mobile' &&
      pbOtpPayload.clinic_id === 1 &&
      pbOtpPayload.mobile === '09125550102',
    'public booking reg OTP request must pass purpose=verify_mobile and clinic_id'
  );
  assert(
    'booking_reg_reveals_code_step_only',
    pbRegCodeStep.hidden === false && pbRegDetailsWrap.hidden === true,
    'reg-code-step must appear while reg-details remains hidden before otp/verify'
  );

  // Step 1b: verify OTP -> reveals reg-details.
  pbRegCode.value = '۶۵۴۳۲۱';
  pbRegVerifyBtn.dispatchEvent({ type: 'click', target: pbRegVerifyBtn, preventDefault: function () {} });
  await flushPromises();

  var pbVerifyPayload = JSON.parse(pb.fetchCalls[1].init.body);
  assert(
    'booking_reg_otp_verify_reveals_details',
    pb.fetchCalls.length === 2 &&
      pbVerifyPayload.purpose === 'verify_mobile' &&
      pbVerifyPayload.clinic_id === 1 &&
      pbVerifyPayload.code === '654321' &&
      pbRegCodeStep.hidden === true &&
      pbRegDetailsWrap.hidden === false,
    'public booking reg-otp-verify must verify OTP and reveal profile details'
  );

  // Step 2: submit profile without code.
  pbRegGiven.value = 'علی';
  pbRegFamily.value = 'رضایی';
  pbRegNid.value = '۰۰۱۰۰۰۰۰۰۳';
  pbRegBirth.value = '۱۹۹۰-۰۲-۱۰';
  pbRegSubmitBtn.dispatchEvent({ type: 'click', target: pbRegSubmitBtn, preventDefault: function () {} });
  await flushPromises();

  var pbRegCall = pb.fetchCalls[2];
  var pbRegPayload = JSON.parse(pbRegCall.init.body);
  assert(
    'booking_reg_submit_posts_json_and_reloads',
    pbRegCall.url === 'https://example.test/wp-json/clinic/v1/patient/register' &&
      pbRegCall.init.credentials === 'same-origin' &&
      pbRegCall.init.headers['X-WP-Nonce'] === 'pb_step1_nonce' &&
      pbRegPayload.clinic_id === 1 &&
      pbRegPayload.national_id === '0010000003' &&
      pbRegPayload.birth_date === '1990-02-10' &&
      !Object.prototype.hasOwnProperty.call(pbRegPayload, 'code') &&
      pb.getReloadCount() === 1,
    'public booking Step 2 submit must POST normalized JSON with X-WP-Nonce, no code, and reload on registered=true'
  );

  // ---- Test 3: Portal #complete-registration Step 2 form ----
  var portalCfg = makeNode('SCRIPT', { class: 'cpms-patient-portal__config' });
  portalCfg.textContent = JSON.stringify({
    rest_root: 'https://example.test/wp-json/clinic/v1',
    cancel_path: '/appointments/{id}/cancel',
    nonce: 'logged_in_nonce_xyz',
    register_incomplete: true,
    register_has_valid_proof: true,
    register_clinic_id: 3,
    register_mobile: '09125550105',
    register_path: '/patient/register',
  });
  var crForm = makeNode('FORM', { 'data-role': 'complete-registration-form' });
  var crDetails = makeNode('DIV', { 'data-role': 'complete-reg-details-step' });
  var crGiven = makeNode('INPUT', { 'data-role': 'complete-reg-given-name' });
  var crFamily = makeNode('INPUT', { 'data-role': 'complete-reg-family-name' });
  var crNid = makeNode('INPUT', { 'data-role': 'complete-reg-national-id' });
  var crBirth = makeNode('INPUT', { 'data-role': 'complete-reg-birth-date' });
  var crSubmit = makeNode('BUTTON', { 'data-role': 'complete-reg-submit' });
  var crErr = makeNode('DIV', { 'data-role': 'complete-reg-error', hidden: true });
  var crOk = makeNode('DIV', { 'data-role': 'complete-reg-success', hidden: true });
  [crGiven, crFamily, crNid, crBirth, crSubmit].forEach(function (el) { crDetails.appendChild(el); });
  [crDetails, crErr, crOk].forEach(function (el) { crForm.appendChild(el); });

  var crPortal = runScriptWithDom(portalSrc, {
    nodes: [portalCfg, crForm],
    fetchImpl: function (url) {
      if (url.endsWith('/patient/register')) {
        return Promise.resolve({
          ok: true,
          status: 200,
          json: function () { return Promise.resolve({ data: { registered: true, session_issued: true } }); },
        });
      }
      return Promise.reject(new Error('unexpected url: ' + url));
    },
  });

  crGiven.value = 'زهرا';
  crFamily.value = 'کریمی';
  crNid.value = '۰۴۹۹۳۷۰۸۹۹';
  crBirth.value = '۱۹۹۵-۰۱-۱۵';
  crForm.dispatchEvent({ type: 'submit', preventDefault: function () {} });
  await flushPromises();

  var crCall = crPortal.fetchCalls[0];
  var crBody = JSON.parse(crCall.init.body);
  assert(
    'portal_complete_registration_submits_step2_and_reloads',
    crPortal.fetchCalls.length === 1 &&
      crCall.url === 'https://example.test/wp-json/clinic/v1/patient/register' &&
      crCall.init.headers['X-WP-Nonce'] === 'logged_in_nonce_xyz' &&
      crBody.clinic_id === 3 &&
      crBody.mobile === '09125550105' &&
      crBody.national_id === '0499370899' &&
      !Object.prototype.hasOwnProperty.call(crBody, 'code') &&
      crPortal.getReloadCount() === 1,
    'portal complete-registration form must submit Step 2 profile without OTP code and reload'
  );

  if (failures > 0) {
    console.error('\nFailed ' + failures + ' of ' + (checks + failures) + ' checks.');
    process.exit(1);
  }
  console.log('\nPassed ' + checks + ' Slice-2 JS checks.');
}

runTests().catch(function (err) {
  console.error(err);
  process.exit(1);
});
