<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

// 1. Validação estrita da sessão do usuário logado
if (!isset($_SESSION["loggedin_odonto2"]) || $_SESSION["loggedin_odonto2"] !== true || empty($_SESSION["username_odonto2"])) {
    echo json_encode(["status" => "erro", "mensagem" => "Acesso não autorizado. Faça login."]);
    exit;
}

$usuario = $_SESSION["username_odonto2"];

// 2. Conexão com o Banco de Dados MySQL
$conn = new mysqli("localhost", "u839226731_farol", "Meta6595869!", "u839226731_farol");
if ($conn->connect_error) {
    echo json_encode(["status" => "erro", "mensagem" => "Erro na conexão com o banco de dados."]);
    exit;
}
$conn->set_charset("utf8mb4");

// 3. Buscar o saldo e a caixa_postal diretamente da tabela identificacao_odonto2
$stmtUser = $conn->prepare("SELECT saldo_total, caixa_postal FROM identificacao_odonto2 WHERE username = ? LIMIT 1");
$stmtUser->bind_param("s", $usuario);
$stmtUser->execute();
$userData = $stmtUser->get_result()->fetch_assoc();
$stmtUser->close();

if (!$userData) {
    echo json_encode(["status" => "erro", "mensagem" => "Usuário não encontrado na base de dados."]);
    $conn->close();
    exit;
}

// Validação extra: O usuário precisa ter o campo caixa_postal preenchido/existente para prosseguir
if (empty($userData["caixa_postal"])) {
    echo json_encode(["status" => "erro", "mensagem" => "Acesso negado: Caixa postal não configurada ou inválida."]);
    $conn->close();
    exit;
}

$saldoAtual = (int)$userData["saldo_total"];

// 4. Se a requisição do Godot for apenas para OBTER O SALDO INICIAL (GET)
if ($_SERVER["REQUEST_METHOD"] === "GET" || !isset($_POST["valor_aposta"])) {
    echo json_encode([
        "status" => "sucesso",
        "saldo" => $saldoAtual
    ]);
    $conn->close();
    exit;
}

// 5. Processamento do Giro da Roleta (POST)
$valorAposta = (int)$_POST["valor_aposta"];
$valoresPermitidos = [2, 4, 6, 8, 10];

if (!in_array($valorAposta, $valoresPermitidos)) {
    echo json_encode(["status" => "erro", "mensagem" => "Valor de aposta inválido."]);
    $conn->close();
    exit;
}

if ($saldoAtual < $valorAposta) {
    echo json_encode(["status" => "erro", "mensagem" => "Saldo insuficiente."]);
    $conn->close();
    exit;
}

// Transação atômica segura no banco de dados
$conn->begin_transaction();
try {
    // Decrementa o valor apostado
    $stmtDec = $conn->prepare("UPDATE identificacao_odonto2 SET saldo_total = saldo_total - ? WHERE username = ?");
    $stmtDec->bind_param("is", $valorAposta, $usuario);
    $stmtDec->execute();
    $stmtDec->close();

    // Sorteio da Matriz 3x3 no servidor
    $chaves_pecas = ["peca0", "peca1", "peca2", "peca3", "peca4", "peca5"];
    $grid = [];
    for ($l = 0; $l < 3; $l++) {
        $linha = [];
        for ($c = 0; $c < 3; $c++) {
            $linha[] = $chaves_pecas[array_rand($chaves_pecas)];
        }
        $grid[] = $linha;
    }

    // Contagem de linhas, colunas e diagonais completas com 'peca0'
    $linhas_vencedoras = 0;

    // Verificar as 3 Linhas Horizontais
    for ($i = 0; $i < 3; $i++) {
        if ($grid[$i][0] === "peca0" && $grid[$i][1] === "peca0" && $grid[$i][2] === "peca0") {
            $linhas_vencedoras++;
        }
    }

    // Verificar as 3 Colunas Verticais
    for ($i = 0; $i < 3; $i++) {
        if ($grid[0][$i] === "peca0" && $grid[1][$i] === "peca0" && $grid[2][$i] === "peca0") {
            $linhas_vencedoras++;
        }
    }

    // Verificar as 2 Diagonais
    if ($grid[0][0] === "peca0" && $grid[1][1] === "peca0" && $grid[2][2] === "peca0") {
        $linhas_vencedoras++;
    }
    if ($grid[0][2] === "peca0" && $grid[1][1] === "peca0" && $grid[2][0] === "peca0") {
        $linhas_vencedoras++;
    }

    // Trava de segurança para o teto físico do grid (máximo de 8 combinações)
    if ($linhas_vencedoras > 8) {
        $linhas_vencedoras = 8;
    }

    $ganhou = ($linhas_vencedoras > 0);
    $resultadoRodada = 0;

    if ($ganhou) {
        // Prêmio bruto de 127% arredondado para cima
        $premioBruto = (int)(ceil($valorAposta * 1.27 * $linhas_vencedoras));
        
        // Faz com que o valor exibido na tela seja o prêmio total ganho
        $resultadoRodada = $premioBruto;

        // Incrementa o prêmio total no saldo_total do usuário
        $stmtInc = $conn->prepare("UPDATE identificacao_odonto2 SET saldo_total = saldo_total + ? WHERE username = ?");
        $stmtInc->bind_param("is", $premioBruto, $usuario);
        $stmtInc->execute();
        $stmtInc->close();
    } else {
        // Se não ganhou nada na rodada, mostra o valor perdido da aposta
        $resultadoRodada = -$valorAposta;
    }

    $conn->commit();

    // Busca o saldo atualizado pós-jogada direto do banco
    $stmtFinal = $conn->prepare("SELECT saldo_total FROM identificacao_odonto2 WHERE username = ? LIMIT 1");
    $stmtFinal->bind_param("s", $usuario);
    $stmtFinal->execute();
    $novoSaldo = (int)$stmtFinal->get_result()->fetch_assoc()["saldo_total"];
    $stmtFinal->close();

    echo json_encode([
        "status" => "sucesso",
        "grid" => $grid,
        "ganhou" => $ganhou,
        "linhas_vencedoras" => $linhas_vencedoras,
        "resultado" => $resultadoRodada,
        "novo_saldo" => $novoSaldo
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["status" => "erro", "mensagem" => "Erro interno na transação do banco."]);
}

$conn->close();
?>
