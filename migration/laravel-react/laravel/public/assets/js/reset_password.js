// Read token from URL
const urlParams = new URLSearchParams(window.location.search);
const token = urlParams.get("token");

document.addEventListener("DOMContentLoaded", () => {
  const tokenField = document.getElementById("token");
  if (tokenField) tokenField.value = token;
});

function handleResetSubmit(event) {
  event.preventDefault();

  const password = document.getElementById('newPassword').value;
  const confirm = document.getElementById('confirmPassword').value;
  const message = document.getElementById('message');

  const isValid = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{12,}$/.test(password);

  if (!isValid) {
    message.textContent = 'Password must meet complexity requirements.';
    return;
  }

  if (password !== confirm) {
    message.textContent = 'Passwords do not match.';
    return;
  }

  message.classList.remove('text-danger');
  message.classList.add('text-success');
  message.textContent = '✅ Password reset successful. Redirecting...';

  // Redirect after a short delay
  setTimeout(() => {
    window.location.href = "user_loginpage.html";
  }, 2000);
}
