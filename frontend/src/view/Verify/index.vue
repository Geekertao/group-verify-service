<template>
  <div class="verify-container">
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
  width: 100%;
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  background: var(--color-fill-1) url('https://img.geekertao.com/uploads/2026/11/12/57e8263cf4ee484d6a144e82100ddf9c_98b5f82c-6d2a-4d5d-a218-a7165b9f93da.jpg') center / cover no-repeat;
  padding: 20px;
}

@media (max-width: 767px) {
  .verify-container {
    background-image: url('https://img.geekertao.com/uploads/2026/11/12/5ab7fe9f369660013f867f0d8db1add5_c5ff70f8-35b4-478f-8251-8cef2f741813.jpg');
  }
}

.verify-card {
  width: 100%;
  max-width: 460px;
  border-radius: 16px;
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.35);
  background: rgba(255, 255, 255, 0.06);
  border: 1px solid rgba(255, 255, 255, 0.2);
  backdrop-filter: blur(18px) saturate(160%);
  -webkit-backdrop-filter: blur(18px) saturate(160%);
  overflow: hidden;
}

.verify-card :deep(.arco-card-body) {
  background: transparent;
}

.verify-card :deep(.arco-alert) {
  background: rgba(255, 255, 255, 0.1);
  border: 1px solid rgba(255, 255, 255, 0.25);
  color: #fff;
}

.verify-card :deep(.arco-alert .arco-alert-title) {
  color: #fff;
}

.verify-card :deep(.arco-alert .arco-alert-content) {
  color: #fff;
}

.verify-card :deep(.arco-alert .arco-icon) {
  color: #fff;
}

.verify-card :deep(.arco-result .arco-result-title) {
  color: #fff;
  text-shadow: 0 1px 4px rgba(0, 0, 0, 0.5);
}

.verify-card :deep(.arco-result .arco-result-subtitle) {
  color: rgba(255, 255, 255, 0.85);
}

.card-title {
  font-size: 20px;
  font-weight: 600;
  text-align: center;
  color: #fff;
  text-shadow: 0 1px 4px rgba(0, 0, 0, 0.5);
}

.code-display {
  font-size: 36px;
  font-weight: 900;
  letter-spacing: 8px;
  text-align: center;
  color: #fff;
  background: rgba(0, 0, 0, 0.35);
  border: 1px solid rgba(255, 255, 255, 0.3);
  border-radius: 8px;
  padding: 16px 24px;
  margin-bottom: 16px;
  font-family: 'SF Mono', 'Monaco', 'Consolas', monospace;
  text-shadow: 0 1px 4px rgba(0, 0, 0, 0.5);
}

.captcha-container {
  min-height: 200px;
  margin-bottom: 16px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(255, 255, 255, 0.08);
  border-radius: 8px;
  border: 1px dashed rgba(255, 255, 255, 0.35);
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
  background: linear-gradient(135deg, #f8faff 0%, #eef4ff 100%);
  padding: 4px 14px 4px 10px;
  border-radius: 30px;
  border: 1px solid #d0dff0;
  box-shadow: 0 2px 6px rgba(0, 20, 50, 0.06);
  text-decoration: none;
  font-size: 14px;
  color: #2c3e50;
  transition: all 0.2s ease;
  font-family: system-ui, -apple-system, sans-serif;
}

.icp-badge:hover {
  background: linear-gradient(135deg, #eef4ff 0%, #dce8ff 100%);
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
</style>
