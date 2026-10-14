export default [{
    files: ['src/**/*.js'],
    languageOptions: {
        ecmaVersion: 'latest',
        sourceType: 'module',
        globals: {
            Shopware: 'readonly',
            document: 'readonly',
            URL: 'readonly',
            setTimeout: 'readonly',
        },
    },
    rules: {
        'no-undef': 'error',
        'no-unused-vars': ['error', { argsIgnorePattern: '^_' }],
        'no-eval': 'error',
        'eqeqeq': ['error', 'always'],
        'no-var': 'error',
        'prefer-const': 'error',
        'no-constant-condition': 'error',
        'no-unreachable': 'error',
    },
}];
