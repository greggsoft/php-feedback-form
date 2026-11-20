"use strict";

const form = document.querySelector("#contact-form");
const submitButton = document.querySelector("#submit-button");
const captchaImage = document.querySelector("#captcha-image");
const captchaRefresh = document.querySelector("#captcha-refresh");
const messageInput = document.querySelector("#message");
const messageCounter = document.querySelector("#message-counter");
const formError = document.querySelector("#form-error");
const resultDialog = document.querySelector("#result-dialog");
const dialogIcon = document.querySelector("#dialog-icon");
const dialogTitle = document.querySelector("#dialog-title");
const dialogMessage = document.querySelector("#dialog-message");
const dialogClose = document.querySelector("#dialog-close");

const fields = {
  subject: form.elements.subject,
  full_name: form.elements.full_name,
  phone: form.elements.phone,
  email: form.elements.email,
  message: form.elements.message,
  captcha: form.elements.captcha,
  consent: form.elements.consent,
};

const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
const namePattern = /^[\p{L}\s.'’-]+$/u;
const allowedPhonePattern = /^[+\d\s()-]+$/;

function getErrorElement(name) {
  return document.querySelector(`#${name}-error`);
}

function setFieldError(name, message) {
  const field = fields[name];
  const errorElement = getErrorElement(name);

  if (!field || !errorElement) {
    return;
  }

  field.classList.toggle("is-invalid", Boolean(message));
  field.setAttribute("aria-invalid", String(Boolean(message)));
  errorElement.textContent = message;

  if (message) {
    field.setAttribute("aria-describedby", errorElement.id);
  } else {
    field.removeAttribute("aria-describedby");
  }
}

function clearErrors() {
  Object.keys(fields).forEach((name) => setFieldError(name, ""));
  formError.textContent = "";
}

function validateForm() {
  const errors = {};
  const values = {
    subject: fields.subject.value.trim(),
    full_name: fields.full_name.value.trim(),
    phone: fields.phone.value.trim(),
    email: fields.email.value.trim(),
    message: fields.message.value.trim(),
    captcha: fields.captcha.value.trim(),
  };

  if (!values.subject) {
    errors.subject = "Выберите тему обращения.";
  }

  if (!values.full_name) {
    errors.full_name = "Укажите ФИО.";
  } else if (values.full_name.length > 255 || !namePattern.test(values.full_name)) {
    errors.full_name = "Используйте только буквы, пробелы, дефисы и апострофы.";
  }

  const phoneDigits = values.phone.replace(/\D/g, "");
  if (!values.phone) {
    errors.phone = "Укажите номер телефона.";
  } else if (
    values.phone.length > 255 ||
    !allowedPhonePattern.test(values.phone) ||
    phoneDigits.length < 10 ||
    phoneDigits.length > 15
  ) {
    errors.phone = "Введите корректный номер телефона.";
  }

  if (!values.email) {
    errors.email = "Укажите email.";
  } else if (values.email.length > 255 || !emailPattern.test(values.email)) {
    errors.email = "Введите корректный email.";
  }

  if (!values.message) {
    errors.message = "Введите сообщение.";
  } else if (values.message.length > 4000) {
    errors.message = "Сообщение не должно превышать 4000 символов.";
  }

  if (!values.captcha) {
    errors.captcha = "Введите код с картинки.";
  }

  if (!fields.consent.checked) {
    errors.consent = "Необходимо согласие на обработку данных.";
  }

  Object.entries(errors).forEach(([name, message]) => {
    setFieldError(name, message);
  });

  return errors;
}

async function loadCaptcha() {
  captchaRefresh.disabled = true;
  captchaImage.removeAttribute("src");
  captchaImage.alt = "Загрузка кода проверки";

  try {
    const response = await fetch("index.php?action=captcha", {
      headers: { Accept: "application/json" },
      cache: "no-store",
    });
    const result = await response.json();

    if (!response.ok || !result.image) {
      throw new Error(result.message || "Не удалось загрузить captcha.");
    }

    captchaImage.src = result.image;
    captchaImage.alt = "Код проверки";
  } catch (error) {
    captchaImage.alt = "Код проверки недоступен";
    setFieldError("captcha", error.message);
  } finally {
    captchaRefresh.disabled = false;
  }
}

function showResult(success, title, message) {
  dialogIcon.classList.toggle("is-error", !success);
  dialogTitle.textContent = title;
  dialogMessage.textContent = message;

  if (typeof resultDialog.showModal === "function") {
    resultDialog.showModal();
    return;
  }

  window.alert(`${title}\n\n${message}`);
}

function setLoading(isLoading) {
  submitButton.disabled = isLoading;
  submitButton.classList.toggle("is-loading", isLoading);
  form.setAttribute("aria-busy", String(isLoading));
}

form.addEventListener("submit", async (event) => {
  event.preventDefault();
  clearErrors();

  const errors = validateForm();
  if (Object.keys(errors).length > 0) {
    fields[Object.keys(errors)[0]].focus();
    formError.textContent = "Проверьте выделенные поля.";
    return;
  }

  setLoading(true);

  try {
    const response = await fetch(form.action, {
      method: "POST",
      body: new FormData(form),
      headers: { Accept: "application/json" },
    });
    const result = await response.json();

    if (result.errors) {
      Object.entries(result.errors).forEach(([name, message]) => {
        setFieldError(name, message);
      });
    }

    if (!response.ok || !result.success) {
      throw new Error(result.message || "Не удалось отправить сообщение.");
    }

    form.reset();
    messageCounter.textContent = "0 / 4000";
    showResult(true, "Сообщение отправлено", result.message);
  } catch (error) {
    formError.textContent = error.message;
    showResult(false, "Ошибка отправки", error.message);
  } finally {
    setLoading(false);
    fields.captcha.value = "";
    await loadCaptcha();
  }
});

Object.entries(fields).forEach(([name, field]) => {
  const eventName = field.type === "checkbox" || field.tagName === "SELECT"
    ? "change"
    : "input";

  field.addEventListener(eventName, () => setFieldError(name, ""));
});

messageInput.addEventListener("input", () => {
  messageCounter.textContent = `${messageInput.value.length} / 4000`;
});

captchaRefresh.addEventListener("click", () => {
  fields.captcha.value = "";
  setFieldError("captcha", "");
  loadCaptcha();
});

dialogClose.addEventListener("click", () => resultDialog.close());
resultDialog.addEventListener("click", (event) => {
  if (event.target === resultDialog) {
    resultDialog.close();
  }
});

loadCaptcha();
