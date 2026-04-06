export const testUsers = {
  admin: {
    email: 'e2eAdmin@2fauth.app',
    password: 'password',
    name: 'E2E Admin',
  },
  user: {
    email: 'e2eUser@2fauth.app',
    password: 'password',
    name: 'E2E User',
  },
  encrypted: {
    email: 'e2eEncrypted@2fauth.app',
    password: 'password',
    name: 'E2E Encrypted',
  },
  newRegistration: {
    name: 'E2E New User',
    email: 'e2enew@example.com',
    password: 'TestPassword123!',
  },
};

export const routes = {
  login: '/login',
  register: '/register',
  accounts: '/accounts',
  start: '/start',
  createAccount: '/account/create',
  importAccounts: '/account/import',
  setupEncryption: '/setup-encryption',
  unlockVault: '/unlock-vault',
  groups: '/groups',
  createGroup: '/group/create',
  settingsOptions: '/settings/options',
  settingsBackup: '/settings/backup',
  about: '/about',
};

/**
 * Selectors derived from the 2FAuth-Components ID generator.
 *
 * Pattern: {prefix}{FieldName}
 *   email field  → #emlEmail
 *   password     → #pwdPassword
 *   text field   → #txt{FieldName}
 *   select       → #sel{FieldName}
 *   button       → #btn{ButtonId}
 *   validation   → #valError{FieldName}
 */
export const sel = {
  // Login form
  legacyLoginForm: '#frmLegacyLogin',
  webauthnLoginForm: '#frmWebauthnLogin',
  emailInput: '#emlEmail',
  passwordInput: '#pwdPassword',
  signInButton: '#btnSignIn',
  switchToLegacy: '#lnkSignWithLegacy',
  switchToWebauthn: '#lnkSignWithWebauthn',
  resetPasswordLink: '#lnkResetPwd',
  registerLink: '#lnkRegister',
  recoverAccountLink: '#lnkRecoverAccount',
  webauthnContinue: '#btnContinue',

  // Register form
  nameInput: '#txtName',
  registerButton: '#btnRegister',
  signInLink: '#lnkSignIn',
  maybeLaterButton: '#btnMaybeLater',
  registerNewDevice: '#btnRegisterNewDevice',

  // Account create form
  serviceInput: '#txtService',
  accountInput: '#txtAccount',
  secretInput: '#txtSecret',
  otpTypeSelect: '#selOtp_type',
  issuerInput: '#txtIssuer',
  digitsInput: '#txtDigits',
  periodInput: '#txtPeriod',
  algorithmSelect: '#selAlgorithm',

  // Groups
  groupNameInput: '#txtName',

  // Common
  signOutButton: '#lnkSignOut',
  importButton: '#btnImport',

  // Validation errors
  valErrorEmail: '#valErrorEmail',
  valErrorPassword: '#valErrorPassword',
  valErrorName: '#valErrorName',
};
