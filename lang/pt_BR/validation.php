<?php

declare(strict_types=1);

return [
    'required' => 'O campo :attribute é obrigatório.',
    'email' => 'Informe um endereço de e-mail válido.',
    'confirmed' => 'A confirmação de :attribute não confere.',
    'different' => 'A nova senha deve ser diferente da senha atual.',
    'current_password' => 'A senha atual está incorreta.',
    'password' => [
        'letters' => 'A senha deve conter letras.',
        'mixed' => 'A senha deve conter letras maiúsculas e minúsculas.',
        'numbers' => 'A senha deve conter pelo menos um número.',
        'symbols' => 'A senha deve conter pelo menos um símbolo.',
        'uncompromised' => 'Esta senha apareceu em um vazamento de dados. Escolha outra senha.',
    ],
    'min' => [
        'string' => 'O campo :attribute deve ter pelo menos :min caracteres.',
    ],
    'attributes' => [
        'email' => 'e-mail',
        'password' => 'senha',
        'current_password' => 'senha atual',
    ],
];
