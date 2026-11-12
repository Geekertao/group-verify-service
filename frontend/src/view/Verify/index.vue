<template>
  <div class="verify-container">
    <div class="background-shade" aria-hidden="true"></div>

    <a-card class="verify-card" :bordered="false">
      <template #title>
        <div class="card-title">{{ title }}</div>
      </template>

      <a-spin :loading="loading" style="width: 100%">
        <a-result v-if="error" status="error" :title="t('load_failed')" :subtitle="error" />

        <template v-else>
          <a-result v-if="verified" status="success" :title="t('title_success')"
            :subtitle="t('verify_success_subtitle')">
            <template #extra>
              <div class="code-display">{{ code }}</div>

              <a-alert v-if="expireMinutes" type="warning" style="margin-bottom: 16px">
                {{ expireTip }}
              </a-alert>

              <a-space direction="vertical" fill size="medium">
                <a-button type="primary" long @click="copyCode">
                  {{ t('copy_code') }}
                </a-button>
                <a-button long @click="refreshStatus">
                  {{ t('refresh_status') }}
                </a-button>
              </a-space>
            </template>
          </a-result>

          <div v-else class="verify-state">
            <a-alert type="info" style="margin-bottom: 16px">
              {{ t('steps') }}
            </a-alert>

            <div id="captcha" class="captcha-container"></div>

            <a-space direction="vertical" fill size="medium">
              <a-button type="primary" long :disabled="submitting || !captchaReady" @click="startCaptcha">
                {{ captchaReady ? t('start_verify') : t('captcha_loading') }}
              </a-button>
              <a-button long :disabled="submitting" @click="refreshStatus">
                {{ t('refresh_status') }}
              </a-button>
            </a-space>
          </div>
        </template>
      </a-spin>
      <div class="footer">
        <a-typography-text type="secondary" class="footer-text">
          此服务由<a href="https://geekertao.top" target="_blank" style="color:#D45637;">Geekertao</a>提供支持,原项目地址:<a
            href="https://github.com/VanillaNahida/group-verify-service" target="_blank"
            style="color:#1F2328;">Github</a>
        </a-typography-text>
        <a href="https://beian.miit.gov.cn/" target="_blank" class="icp-badge">
          <img src="https://www.geekertao.com/miit_logo.png" alt="工信部备案" />
          鲁ICP备2026027679号-5
        </a>
      </div>
    </a-card>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { Message, Modal } from '@arco-design/web-vue';
import { copyText } from '../../utils/clipboard';
import { toFormBody } from '../../utils/form';
import { parseTicketFromLocation } from '../../utils/url';
import { getStatus, submitCallback } from '../../api/verify';

const props = defineProps({
  t: { type: Function, default: (k) => String(k || '') }
});

const t = props.t;

const ticket = ref('');
const loading = ref(true);
const error = ref('');
const verified = ref(false);
const code = ref('');
const captchaId = ref('');
const expireMinutes = ref(null);
const captchaReady = ref(false);
const submitting = ref(false);

let captchaObj = null;

const title = computed(() => (verified.value ? t('title_success') : t('title_default')));
const expireTip = computed(() => (expireMinutes.value ? t('expire_tip', { minutes: expireMinutes.value }) : ''));

function showExpired() {
  error.value = t('link_expired_or_missing');
  verified.value = false;
  code.value = '';
  expireMinutes.value = null;
}

function initGeetest() {
  if (!captchaId.value) return;

  if (typeof window.initGeetest4 === 'undefined') {
    error.value = t('captcha_component_load_failed');
    captchaReady.value = false;
    return;
  }

  captchaReady.value = false;
  captchaObj = null;
  const container = document.querySelector('#captcha');
  if (container) container.innerHTML = '';

  window.initGeetest4(
    {
      captchaId: captchaId.value,
      product: 'bind',
      language: 'zh-cn',
      timeout: 10000
    },
    (obj) => {
      captchaObj = obj;

      try {
        obj.appendTo('#captcha');
      } catch (e) { }

      obj
        .onReady(() => {
          captchaReady.value = true;
        })
        .onError(() => {
          error.value = t('captcha_init_failed');
          captchaReady.value = false;
        })
        .onSuccess(() => {
          const result = captchaObj && captchaObj.getValidate ? captchaObj.getValidate() : null;
          if (!result) {
            Message.error(t('please_complete_captcha'));
            return;
          }
          submitVerification(result);
        })
        .onClose(() => {
          submitting.value = false;
        });
    }
  );
}

function startCaptcha() {
  if (!captchaReady.value || !captchaObj) {
    Message.warning(t('captcha_loading_wait'));
    return;
  }
  try {
    captchaObj.showCaptcha();
  } catch (e) {
    Message.error(t('captcha_error_refresh'));
  }
}

async function submitVerification(geetestResult) {
  submitting.value = true;

  try {
    const { data } = await submitCallback(
      toFormBody({
        ticket: ticket.value,
        lot_number: geetestResult.lot_number,
        captcha_output: geetestResult.captcha_output,
        pass_token: geetestResult.pass_token,
        gen_time: geetestResult.gen_time
      })
    );
    if (data && data.code === 0 && data.data && data.data.code) {
      verified.value = true;
      code.value = String(data.data.code);
      Message.success(t('verify_success'));
      try {
        await copyText(code.value);
        Message.success(t('code_copied_paste'));
      } catch (e) { }
      return;
    }

    Message.error((data && data.msg) || t('verify_failed_retry'));
    submitting.value = false;
    if (captchaObj) {
      try {
        captchaObj.reset();
      } catch (e) { }
    }
  } catch (e) {
    Message.error(t('network_error_retry'));
    submitting.value = false;
  }
}

async function copyCode() {
  if (!code.value) return;
  try {
    await copyText(code.value);
    Message.success(t('code_copied'));
  } catch (e) {
    Modal.info({
      title: t('copy_failed'),
      content: t('copy_failed_manual_prefix') + code.value,
      hideCancel: true
    });
  }
}

async function refreshStatus() {
  loading.value = true;
  error.value = '';

  try {
    const { data } = await getStatus(ticket.value);

    if (!data || typeof data.code === 'undefined') {
      error.value = t('server_response_invalid');
      return;
    }

    if (data.code === 404) {
      showExpired();
      return;
    }

    if (data.code !== 0) {
      error.value = data.msg || t('load_failed');
      return;
    }

    const expire = data.data && typeof data.data.expire_minutes !== 'undefined' ? Number(data.data.expire_minutes) : null;
    expireMinutes.value = Number.isFinite(expire) && expire > 0 ? Math.ceil(expire) : null;

    if (data.data && data.data.verified) {
      verified.value = true;
      code.value = String(data.data.code || t('code_already_shown'));
      return;
    }

    verified.value = false;
    code.value = '';
    captchaId.value = (data.data && data.data.captcha_id) || '';
    initGeetest();
  } catch (e) {
    error.value = '网络异常，请稍后重试';
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  ticket.value = parseTicketFromLocation();
  if (!ticket.value) {
    loading.value = false;
    error.value = t('invalid_link');
    return;
  }
  refreshStatus();
});
</script>

<style scoped>
.verify-container {
  position: relative;
  isolation: isolate;
  box-sizing: border-box;
  width: 100%;
  min-height: 100vh;
  min-height: 100svh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 32px 20px 40px;
  background: #0b1724 url('https://img.geekertao.com/uploads/2026/11/12/57e8263cf4ee484d6a144e82100ddf9c_98b5f82c-6d2a-4d5d-a218-a7165b9f93da.jpg') center / cover no-repeat;
}

/* 遮罩层：给毛玻璃一层稳定的深色底，blur 才不会糊出脏色 */
.background-shade {
  position: absolute;
  inset: 0;
  z-index: -1;
  pointer-events: none;
  background:
    linear-gradient(135deg, rgba(5, 18, 30, 0.82), rgba(12, 45, 55, 0.6)),
    linear-gradient(180deg, rgba(8, 24, 40, 0.24), rgba(6, 18, 29, 0.72));
}

.verify-card {
  position: relative;
  z-index: 0;
  width: 100%;
  max-width: 480px;
  overflow: hidden;
  border: 1px solid rgba(255, 255, 255, 0.55);
  border-radius: 20px;
  background: rgba(235, 246, 248, 0.6);
  box-shadow:
    0 24px 70px rgba(1, 12, 24, 0.34),
    0 4px 16px rgba(1, 12, 24, 0.14),
    inset 0 1px 0 rgba(255, 255, 255, 0.7);
  -webkit-backdrop-filter: blur(28px) saturate(1.18);
  backdrop-filter: blur(28px) saturate(1.18);
  animation: cardFadeIn 0.45s ease-out both;
}

/* 斜向反光：玻璃感主要来自这一层，必须裁在圆角内且不挡点击 */
.verify-card::before {
  position: absolute;
  inset: 0;
  z-index: 3;
  content: '';
  pointer-events: none;
  background: linear-gradient(125deg, rgba(255, 255, 255, 0.28), transparent 28%, transparent 72%, rgba(255, 255, 255, 0.1));
}

@keyframes cardFadeIn {
  from { opacity: 0; transform: translateY(12px); }
  to { opacity: 1; transform: translateY(0); }
}

/* 头部第二层玻璃：青绿渐变 + 更小半径的模糊 */
.verify-card :deep(.arco-card-header) {
  position: relative;
  z-index: 1;
  padding: 26px 32px 24px;
  border-bottom: 0;
  background: linear-gradient(140deg, rgba(11, 39, 57, 0.86), rgba(13, 74, 80, 0.72));
  -webkit-backdrop-filter: blur(18px) saturate(1.25);
  backdrop-filter: blur(18px) saturate(1.25);
}

.verify-card :deep(.arco-card-header)::after {
  position: absolute;
  right: 0;
  bottom: 0;
  left: 0;
  height: 1px;
  content: '';
  background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.45), transparent);
}

.verify-card :deep(.arco-card-body) {
  position: relative;
  z-index: 1;
  background: transparent;
}

.card-title {
  font-size: 20px;
  font-weight: 600;
  letter-spacing: 0.02em;
  text-align: center;
  color: #f8fffe;
}

.code-display {
  margin-bottom: 16px;
  padding: 20px 18px;
  color: #754b08;
  border: 1px solid #f2cb65;
  border-radius: 12px;
  background: #fff8df;
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.9);
  font-family: 'SF Mono', 'Monaco', 'Consolas', monospace;
  font-size: clamp(30px, 7vw, 42px);
  font-weight: 800;
  letter-spacing: clamp(4px, 1.8vw, 9px);
  line-height: 1.1;
  text-align: center;
  word-break: break-all;
}

.captcha-container {
  min-height: 200px;
  margin-bottom: 16px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 1px dashed rgba(18, 34, 53, 0.25);
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.5);
}

.verify-state {
  padding: 8px 0;
}

.footer {
  margin-top: 24px;
  text-align: center;
}

.footer-text {
  display: block;
  margin: 4px 0;
  color: var(--color-text-2);
}

.icp-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: rgba(255, 255, 255, 0.78);
  padding: 4px 14px 4px 10px;
  border-radius: 30px;
  border: 1px solid rgba(18, 34, 53, 0.14);
  box-shadow: 0 2px 6px rgba(0, 20, 50, 0.06);
  text-decoration: none;
  font-size: 14px;
  color: #2c3e50;
  transition: all 0.2s ease;
  font-family: system-ui, -apple-system, sans-serif;
}

.icp-badge:hover {
  background: rgba(255, 255, 255, 0.92);
  border-color: #8bb3e0;
  box-shadow: 0 4px 12px rgba(30, 80, 180, 0.12);
  transform: translateY(-1px);
  color: #1a2a44;
}

.icp-badge img {
  height: 18px;
  width: auto;
  vertical-align: middle;
  filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.05));
}

/* 不支持 backdrop-filter 时给一层更实的高透明白底，保证文字可读 */
@supports not ((backdrop-filter: blur(1px)) or (-webkit-backdrop-filter: blur(1px))) {
  .verify-card {
    background: rgba(240, 248, 250, 0.94);
  }
}

@media (max-width: 767px) {
  .verify-container {
    padding: 18px 12px calc(18px + env(safe-area-inset-bottom));
    background-image: url('https://img.geekertao.com/uploads/2026/11/12/5ab7fe9f369660013f867f0d8db1add5_c5ff70f8-35b4-478f-8251-8cef2f741813.jpg');
  }

  .verify-card {
    border-radius: 16px;
  }

  .verify-card :deep(.arco-card-header) {
    padding: 22px 20px 20px;
  }
}

@media (prefers-reduced-motion: reduce) {
  .verify-card {
    animation: none;
  }
}
</style>
