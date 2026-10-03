<?php

require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/functions.php';

$tituloPagina = 'Libro Diario';
$activo = 'diario';


/* =========================================================
   SIGUIENTE NÚMERO DE PARTIDA
========================================================= */

$siguiente = siguienteNumeroPartida($conn);


/* =========================================================
   RESUMEN DEL LIBRO DIARIO
   ========================================================= */

$totalPartidas = 0;
$res = $conn->query('SELECT COUNT(*) AS total FROM asientos');
if ($res && ($fila = $res->fetch_assoc())) {
    $totalPartidas = (int)$fila['total'];
    $res->free();
}

$ultimaPartida = null;
$res = $conn->query('
    SELECT numero_partida, fecha, concepto
    FROM asientos
    ORDER BY numero_partida DESC
    LIMIT 1
');
if ($res && ($fila = $res->fetch_assoc())) {
    $ultimaPartida = $fila;
    $res->free();
}

$ultimasPartidas = [];
$res = $conn->query("
    SELECT
        a.numero_partida,
        a.fecha,
        a.concepto,
        COALESCE(SUM(d.debe), 0) AS total_debe,
        COALESCE(SUM(d.haber), 0) AS total_haber
    FROM asientos a
    INNER JOIN detalle_asientos d
        ON d.asiento_id = a.id
    GROUP BY a.id, a.numero_partida, a.fecha, a.concepto
    ORDER BY a.numero_partida DESC
    LIMIT 5
");
if ($res) {
    $ultimasPartidas = $res->fetch_all(MYSQLI_ASSOC);
    $res->free();
}


/* =========================================================
   OBTENER CUENTAS DESDE EL CATÁLOGO
========================================================= */

$cuentas = $conn->query("
    SELECT
        id,
        codigo,
        nombre,
        tipo,
        naturaleza,
        nivel
    FROM cuentas
    WHERE permite_movimiento = 1
      AND activa = 1
    ORDER BY codigo ASC
");

$listaCuentas = [];

while ($row = $cuentas->fetch_assoc()) {

    $listaCuentas[] = $row;

}

// Obtener tipos de cuenta para el filtro
$tiposCuenta = [
    'activo' => 'Activo',
    'pasivo' => 'Pasivo',
    'patrimonio' => 'Patrimonio',
    'costo_gasto' => 'Costo / Gasto',
    'ingreso' => 'Ingreso',
    'cierre' => 'Cierre',
    'orden' => 'Orden',
];


/* =========================================================
   MENSAJES
========================================================= */

$mensaje = '';
$tipoMensaje = '';


if (isset($_GET['ok'])) {

    $mensaje =
        'Partida registrada correctamente. Debe = Haber ✔';

    $tipoMensaje = 'success';

}


if (isset($_GET['err'])) {

    $errores = [

        'descuadre' =>
            'La partida no cumple la Partida Doble: el total Debe debe ser igual al total Haber.',

        'incompleto' =>
            'Debes completar la fecha, el concepto y al menos dos líneas con cuenta y monto.',

        'cuenta' =>
            'Una o más cuentas seleccionadas no son válidas.',

        'fecha' =>
            'La fecha del asiento no es válida.',

        'monto' =>
            'Los importes deben ser números positivos con máximo dos decimales.',

        'linea' =>
            'Cada línea debe tener una cuenta y un importe en un solo lado: Debe o Haber.',

        'csrf' =>
            'La sesión del formulario venció. Actualiza la página e intenta nuevamente.',

        'periodo_cerrado' =>
            'La fecha pertenece a un período ya cerrado contablemente. Usa una fecha posterior al último cierre.',

        'general' =>
            'No se pudo guardar la partida. Intenta nuevamente o revisa la base de datos.'

    ];


    $errorKey = is_string($_GET['err']) ? $_GET['err'] : '';
    $mensaje =
        $errores[$errorKey]
        ?? 'Ocurrió un error al guardar la partida.';

    $tipoMensaje = 'error';

}


require_once 'includes/header.php';

?>


<!-- =========================================================
     TÍTULO
========================================================= -->

<h1 class="page-title">
    Libro Diario
</h1>

<div class="card diario-hero-card">

    <div class="diario-hero-copy">

        <p class="diario-kicker">Registro contable</p>

        <h2>Asientos de doble partida</h2>

        <p class="help-text">
            Registra y consulta los asientos contables con validación de
            Debe = Haber. El correlativo se asigna automáticamente y cada
            movimiento queda trazado en el Libro Mayor y los Estados Financieros.
        </p>

        <div class="diario-compliance" aria-label="Criterios contables aplicados">
            <span>✔ Partida doble</span>
            <span>✔ NIIF para PYMES</span>
            <span>✔ Código de Comercio de El Salvador</span>
        </div>

    </div>

</div>


<!-- =========================================================
     MENSAJE
========================================================= -->

<?php if ($mensaje): ?>

    <div class="alert alert-<?php echo $tipoMensaje; ?>">

        <?php
        echo htmlspecialchars($mensaje);
        ?>

    </div>

<?php endif; ?>



<!-- =========================================================
     RESUMEN DEL LIBRO DIARIO
     ========================================================= -->

<div class="diario-summary-grid">

    <div class="diario-summary-card">
        <span class="diario-summary-label">Partidas registradas</span>
        <strong class="diario-summary-value"><?php echo $totalPartidas; ?></strong>
        <small>En el libro diario</small>
    </div>

    <div class="diario-summary-card">
        <span class="diario-summary-label">Última partida</span>
        <strong class="diario-summary-value">
            <?php echo $ultimaPartida
                ? '#' . (int)$ultimaPartida['numero_partida']
                : '—'; ?>
        </strong>
        <small>
            <?php echo $ultimaPartida
                ? date('d/m/Y', strtotime($ultimaPartida['fecha']))
                : 'Sin registros'; ?>
        </small>
    </div>

    <div class="diario-summary-card diario-summary-next">
        <span class="diario-summary-label">Siguiente correlativo</span>
        <strong class="diario-summary-value">#<?php echo $siguiente; ?></strong>
        <small>Se asigna automáticamente</small>
    </div>

</div>

<div class="diario-info">
    <strong>¿Por qué aparece el número siguiente?</strong>
    El correlativo se asigna automáticamente. Si la última partida guardada es
    la #<?php echo $ultimaPartida ? (int)$ultimaPartida['numero_partida'] : 0; ?>,
    el formulario está mostrando la #<?php echo $siguiente; ?>. El historial
    se ordena por número de partida para que puedas seguir la secuencia completa
    sin perder de vista la fecha de cada asiento.
</div>



<!-- =========================================================
     NUEVA PARTIDA
========================================================= -->

<div class="card diario-form-card" id="nueva-partida">

    <div class="diario-card-header">

        <div>
            <p class="diario-kicker">Registro contable</p>

            <h2>
                Nueva partida #<?php echo $siguiente; ?>
            </h2>
        </div>

        <span class="diario-badge">Correlativo automático</span>

    </div>


    <p class="help-text">

        Validación obligatoria de Partida Doble:
        el sistema bloqueará el guardado si el total Debe
        no es igual al total Haber.

    </p>

    <div class="diario-compliance" aria-label="Criterios contables aplicados">

        <span>✔ Partida doble</span>
        <span>✔ NIIF para PYMES</span>
        <span>✔ Código de Comercio de El Salvador</span>

    </div>


    <form
        id="formAsiento"
        action="guardar_asiento.php"
        method="POST"
    >

        <input
            type="hidden"
            name="_csrf"
            value="<?php echo htmlspecialchars(csrfToken('asientos'), ENT_QUOTES, 'UTF-8'); ?>"
        >


        <!-- =================================================
             FECHA Y CONCEPTO
        ================================================== -->

        <div class="row-flex">


            <div>

                <label for="fecha">
                    Fecha
                </label>


                <input
                    type="date"
                    name="fecha"
                    id="fecha"
                    value="<?php echo date('Y-m-d'); ?>"
                    required
                >

            </div>



            <div class="diario-concept-field">

                <label for="concepto">
                    Concepto de la partida
                </label>


                <input
                    type="text"
                    name="concepto"
                    id="concepto"
                    maxlength="255"
                    placeholder="Ej. Venta de mercadería al contado"
                    required
                >

            </div>


        </div>



        <!-- =================================================
             TABLA PARA REGISTRAR PARTIDA
        ================================================== -->

        <div
            class="table-scroll diario-lines-wrap"
        >

            <table id="tablaLineas">


                <thead>

                    <tr>

                        <th style="width:30%">
                            Cuenta
                        </th>


                        <th style="width:26%">
                            Detalle / Glosa
                        </th>


                        <th style="width:14%">
                            Debe
                        </th>


                        <th style="width:14%">
                            Haber
                        </th>


                        <th style="width:5%">
                        </th>

                    </tr>

                </thead>



                <tbody>


                    <?php for ($i = 0; $i < 4; $i++): ?>


                        <tr>


                            <!-- CUENTA -->

                            <td>

                                <select
                                    name="cuenta_id[]"
                                >

                                    <option value="">
                                        -- Selecciona --
                                    </option>


                                    <?php foreach ($listaCuentas as $c): ?>

                                        <?php
                                        $naturalezaLabel = $c['naturaleza'] === 'deudora' ? 'D' : 'A';
                                        $tipoLabel = $tiposCuenta[$c['tipo']] ?? $c['tipo'];
                                        ?>

                                        <option
                                            value="<?php echo $c['id']; ?>"
                                            data-tipo="<?php echo htmlspecialchars($c['tipo']); ?>"
                                            data-naturaleza="<?php echo htmlspecialchars($c['naturaleza']); ?>"
                                            data-codigo="<?php echo htmlspecialchars($c['codigo']); ?>"
                                        >

                                            <?php

                                            echo htmlspecialchars(
                                                $c['codigo']
                                                . ' - '
                                                . $c['nombre']
                                                . ' (' . $naturalezaLabel . ')'
                                            );

                                            ?>

                                        </option>


                                    <?php endforeach; ?>


                                </select>

                            </td>



                            <!-- DETALLE -->

                            <td>

                                <input
                                    type="text"
                                    name="detalle[]"
                                    placeholder="Opcional"
                                >

                            </td>



                            <!-- DEBE -->

                            <td>

                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    name="debe[]"
                                    class="campo-debe"
                                    value="0"
                                >

                            </td>



                            <!-- HABER -->

                            <td>

                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    name="haber[]"
                                    class="campo-haber"
                                    value="0"
                                >

                            </td>



                            <!-- ELIMINAR -->

                            <td class="text-center">

                                <button
                                    type="button"
                                    class="btn danger btn-quitar diario-remove-line"

                                >

                                    ✕

                                </button>

                            </td>


                        </tr>


                    <?php endfor; ?>


                </tbody>



                <!-- =================================================
                     TOTALES
                ================================================== -->

                <tfoot>

                    <tr class="total-row">


                        <td
                            colspan="2"
                            class="text-right"
                        >

                            TOTALES

                        </td>


                        <td
                            id="totalDebe"
                            class="text-right"
                        >

                            $0.00

                        </td>


                        <td
                            id="totalHaber"
                            class="text-right"
                        >

                            $0.00

                        </td>


                        <td>
                        </td>


                    </tr>

                </tfoot>


            </table>

        </div>



        <!-- =================================================
             BOTONES
        ================================================== -->

        <div class="diario-form-actions">

            <button
                type="button"
                id="btnAgregarLinea"
                class="btn secondary"
            >

                + Agregar línea

            </button>

            <span
                id="estadoCuadre"
                class="diario-balance-status"
            >
            </span>

            <button
                type="submit"
                id="btnGuardar"
                class="btn diario-save-button"
            >

                Guardar Partida

            </button>

        </div>


    </form>

</div>



<!-- =========================================================
     LIBRO DIARIO
========================================================= -->

<div class="card diario-latest-card" id="ultimas-partidas">

    <div class="diario-card-header">

        <div>
            <p class="diario-kicker">Seguimiento</p>

            <h2>Últimas partidas</h2>
        </div>

        <a class="btn secondary diario-history-link" href="#historial">
            Ver historial completo
        </a>

    </div>

    <p class="help-text">
        Estas partidas están ordenadas por número correlativo para que
        puedas ver rápidamente los últimos movimientos registrados.
    </p>

    <div class="table-scroll diario-latest-scroll">

        <table class="diario-latest-table">

            <thead>
                <tr>
                    <th>Partida</th>
                    <th>Fecha</th>
                    <th>Concepto</th>
                    <th class="text-right">Debe</th>
                    <th class="text-right">Haber</th>
                </tr>
            </thead>

            <tbody>
                <?php if ($ultimasPartidas): ?>
                    <?php foreach ($ultimasPartidas as $ultima): ?>
                        <tr>
                            <td>
                                <span class="diario-partida-chip">
                                    #<?php echo (int)$ultima['numero_partida']; ?>
                                </span>
                            </td>
                            <td>
                                <?php echo date(
                                    'd/m/Y',
                                    strtotime($ultima['fecha'])
                                ); ?>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($ultima['concepto']); ?>
                            </td>
                            <td class="text-right">
                                <?php echo fmt($ultima['total_debe']); ?>
                            </td>
                            <td class="text-right">
                                <?php echo fmt($ultima['total_haber']); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="text-center">
                            Todavía no existen partidas registradas.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>

        </table>

    </div>

</div>



<div class="card" id="historial">

    <h2>
        Libro Diario
    </h2>

    <p class="help-text">
        Historial completo ordenado por número de partida. La fecha de cada
        asiento se muestra dentro de su tabla.
    </p>


    <?php


    /* =====================================================
       CONSULTAR ASIENTOS
    ====================================================== */

    $sql = "

        SELECT

            a.id AS asiento_id,

            a.numero_partida,

            a.fecha,

            a.concepto,


            d.id AS detalle_id,

            d.detalle,

            d.debe,

            d.haber,


            c.codigo,

            c.nombre AS cuenta_nombre


        FROM asientos a


        INNER JOIN detalle_asientos d
            ON d.asiento_id = a.id


        INNER JOIN cuentas c
            ON c.id = d.cuenta_id


        ORDER BY

            a.numero_partida ASC,

            d.id ASC

    ";


    $res = $conn->query($sql);



    /* =====================================================
       AGRUPAR POR FECHA Y ASIENTO
    ====================================================== */

    $partidas = [];


    if ($res) {
        while ($r = $res->fetch_assoc()) {

            $asientoId = (int)$r['asiento_id'];

            if (!isset($partidas[$asientoId])) {

                $partidas[$asientoId] = [

                    'numero_partida' =>
                        $r['numero_partida'],

                    'fecha' =>
                        $r['fecha'],

                    'concepto' =>
                        $r['concepto'],

                    'detalles' =>
                        []

                ];

            }

            $partidas[$asientoId]['detalles'][] = $r;

        }

        $res->free();
    }


    ?>



    <!-- =====================================================
         SI NO HAY PARTIDAS
    ====================================================== -->

    <?php if (empty($partidas)): ?>


        <div class="alert">

            Todavía no existen partidas registradas.

        </div>


    <?php endif; ?>



    <!-- =====================================================
         RECORRER PARTIDAS EN ORDEN DE CORRELATIVO
    ====================================================== -->

    <?php foreach ($partidas as $asiento): ?>







                <div class="diario-history-asiento">


                    <!-- =========================================
                         INFORMACIÓN DEL ASIENTO
                    ========================================== -->

                    <div class="diario-history-info">


                        <div class="diario-history-number">

                            PARTIDA Nº

                            <?php

                            echo htmlspecialchars(
                                $asiento['numero_partida']
                            );

                            ?>

                        </div>



                        <div class="diario-history-concept">

                            <strong>
                                Concepto:
                            </strong>


                            <?php

                            echo htmlspecialchars(
                                $asiento['concepto']
                            );

                            ?>

                        </div>


                    </div>



                    <!-- =========================================
                         TABLA DEL ASIENTO
                    ========================================== -->

                    <div class="table-scroll">


                        <table>


                            <thead>

                                <tr>


                                    <th>
                                        Fecha
                                    </th>


                                    <th>
                                        Código
                                    </th>


                                    <th>
                                        Cuenta
                                    </th>

                                    <th class="text-right">
                                        Debe
                                    </th>


                                    <th class="text-right">
                                        Haber
                                    </th>


                                </tr>

                            </thead>



                            <tbody>


                                <?php


                                $totalDebe =
                                    0;


                                $totalHaber =
                                    0;


                                ?>



                                <?php foreach ($asiento['detalles'] as $detalle): ?>


                                    <?php


                                    $totalDebe +=
                                        (float)$detalle['debe'];


                                    $totalHaber +=
                                        (float)$detalle['haber'];


                                    ?>


                                    <tr>


                                        <!-- FECHA -->

                                        <td>

                                            <?php

                                            echo date(
                                                'd/m/Y',
                                                strtotime(
                                                    $asiento['fecha']
                                                )
                                            );

                                            ?>

                                        </td>



                                        <!-- CÓDIGO -->

                                        <td>

                                            <?php

                                            echo htmlspecialchars(
                                                $detalle['codigo']
                                            );

                                            ?>

                                        </td>



                                        <!-- CUENTA -->

                                        <td>


                                            <?php

                                            echo htmlspecialchars(
                                                $detalle['cuenta_nombre']
                                            );

                                            ?>


                                            <?php

                                            if (
                                                !empty(
                                                    $detalle['detalle']
                                                )
                                            ):

                                            ?>

                                                <br>


                                                <small class="diario-history-detail">

                                                    <?php

                                                    echo htmlspecialchars(
                                                        $detalle['detalle']
                                                    );

                                                    ?>

                                                </small>


                                            <?php endif; ?>


                                        </td>



                                        <!-- DEBE -->

                                        <td class="text-right">


                                            <?php

                                            if (
                                                (float)$detalle['debe'] > 0
                                            ) {

                                                echo fmt(
                                                    $detalle['debe']
                                                );

                                            }

                                            ?>


                                        </td>



                                        <!-- HABER -->

                                        <td class="text-right">


                                            <?php

                                            if (
                                                (float)$detalle['haber'] > 0
                                            ) {

                                                echo fmt(
                                                    $detalle['haber']
                                                );

                                            }

                                            ?>


                                        </td>


                                    </tr>


                                <?php endforeach; ?>


                            </tbody>



                            <!-- =====================================
                                 TOTALES
                            ====================================== -->

                            <tfoot>


                                <tr class="total-row">


                                    <td
                                        colspan="3"
                                        class="text-right"
                                    >

                                        TOTALES

                                    </td>



                                    <td class="text-right">

                                        <?php

                                        echo fmt(
                                            $totalDebe
                                        );

                                        ?>

                                    </td>



                                    <td class="text-right">

                                        <?php

                                        echo fmt(
                                            $totalHaber
                                        );

                                        ?>

                                    </td>


                                </tr>


                            </tfoot>


                        </table>


                    </div>


                </div>


            <?php endforeach; ?>


</div>



<!-- =========================================================
     JAVASCRIPT
========================================================= -->

<script>

document.addEventListener('DOMContentLoaded', function() {

/* =========================================================
   ELEMENTOS
========================================================= */

const tbody =
    document.querySelector(
        '#tablaLineas tbody'
    );


const cuentasOptionsHTML =
    document.querySelector(
        '#tablaLineas select'
    ).innerHTML;


/* =========================================================
   FILTRAR CUENTAS POR TIPO Y BÚSQUEDA
========================================================= */

function filtrarCuentas() {
    const filtroTipo = document.getElementById('filtroTipo').value;
    const buscarTexto = document.getElementById('buscarCuenta').value.toLowerCase();

    document.querySelectorAll('#tablaLineas tbody tr').forEach(fila => {
        const select = fila.querySelector('select[name="cuenta_id[]"]');
        if (!select) return;

        // Obtener todas las opciones del select
        const opciones = Array.from(select.querySelectorAll('option'));
        const optionVacio = opciones[0]; // La primera opción es "-- Selecciona --"

        // Filtrar opciones
        const opcionesFiltradas = opciones.slice(1).filter(opcion => {
            const tipo = opcion.dataset.tipo || '';
            const codigo = opcion.dataset.codigo || '';
            const texto = opcion.textContent.toLowerCase();

            const coincideTipo = !filtroTipo || tipo === filtroTipo;
            const coincideBusqueda = !buscarTexto || texto.includes(buscarTexto) || codigo.includes(buscarTexto);

            return coincideTipo && coincideBusqueda;
        });

        // Reconstruir el select
        select.innerHTML = '';
        select.appendChild(optionVacio);
        opcionesFiltradas.forEach(opcion => select.appendChild(opcion));
    });
}

// Eventos para filtros
document.getElementById('filtroTipo').addEventListener('change', filtrarCuentas);
document.getElementById('buscarCuenta').addEventListener('input', filtrarCuentas);



/* =========================================================
   CALCULAR TOTALES
========================================================= */

function calcularTotales() {


    let debe = 0;

    let haber = 0;



    document
        .querySelectorAll('.campo-debe')
        .forEach(i => {

            debe +=
                parseFloat(
                    i.value || 0
                );

        });



    document
        .querySelectorAll('.campo-haber')
        .forEach(i => {

            haber +=
                parseFloat(
                    i.value || 0
                );

        });

    // Una línea puede tener Debe o Haber, nunca ambos. También se cuentan
    // únicamente las filas que tienen cuenta y un importe real.
    let lineasValidas = 0;
    let hayLineaInvalida = false;

    document.querySelectorAll('#tablaLineas tbody tr').forEach(fila => {
        const cuenta = fila.querySelector('select[name="cuenta_id[]"]');
        const campoDebe = fila.querySelector('.campo-debe');
        const campoHaber = fila.querySelector('.campo-haber');
        const valorDebe = Math.round((parseFloat(campoDebe?.value || '0') || 0) * 100);
        const valorHaber = Math.round((parseFloat(campoHaber?.value || '0') || 0) * 100);
        const tieneCuenta = !!cuenta?.value;
        const tieneImporte = valorDebe > 0 || valorHaber > 0;

        if (tieneCuenta && tieneImporte && !(valorDebe > 0 && valorHaber > 0)) {
            lineasValidas++;
        } elseif (tieneCuenta || tieneImporte) {
            hayLineaInvalida = true;
        }
    });

    const debeCentavos = Math.round(debe * 100);
    const haberCentavos = Math.round(haber * 100);


    /* -----------------------------------------------
       MOSTRAR TOTALES
    ------------------------------------------------ */

    document.getElementById(
        'totalDebe'
    ).textContent =
        '$' + debe.toFixed(2);


    document.getElementById(
        'totalHaber'
    ).textContent =
        '$' + haber.toFixed(2);



    /* -----------------------------------------------
       ESTADO
    ------------------------------------------------ */

    const estado =
        document.getElementById(
            'estadoCuadre'
        );


    const btnGuardar =
        document.getElementById(
            'btnGuardar'
        );



    if (
        !hayLineaInvalida &&
        lineasValidas >= 2 &&
        debeCentavos > 0 &&
        debeCentavos === haberCentavos
    ) {


        estado.textContent =
            '✔ Partida cuadrada (Debe = Haber)';


        estado.className =
            'diario-balance-status cuadrado';


        btnGuardar.disabled =
            false;


    } else {


        estado.textContent = hayLineaInvalida
            ? '✕ Cada línea debe tener solo Debe o Haber'
            : (lineasValidas < 2
                ? '✕ Agrega al menos dos líneas con cuenta e importe'
                : '✕ Descuadre: Debe ≠ Haber');


        estado.className =
            'diario-balance-status descuadre';


        btnGuardar.disabled =
            true;

    }

}



/* =========================================================
   AGREGAR LÍNEA
========================================================= */

document
    .getElementById(
        'btnAgregarLinea'
    )
    .addEventListener(
        'click',
        () => {


            const tr =
                document.createElement(
                    'tr'
                );



            tr.innerHTML = `

                <td>

                    <select
                        name="cuenta_id[]"
                    >

                        ${cuentasOptionsHTML}

                    </select>

                </td>


                <td>

                    <input
                        type="text"
                        name="detalle[]"
                        placeholder="Opcional"
                    >

                </td>


                <td>

                    <input
                        type="number"
                        step="0.01"
                        min="0"
                        name="debe[]"
                        class="campo-debe"
                        value="0"
                    >

                </td>


                <td>

                    <input
                        type="number"
                        step="0.01"
                        min="0"
                        name="haber[]"
                        class="campo-haber"
                        value="0"
                    >

                </td>


                <td class="text-center">

                    <button
                        type="button"
                        class="btn danger btn-quitar diario-remove-line"

                    >

                        ✕

                    </button>

                </td>

            `;



            tbody.appendChild(
                tr
            );


            ligarEventos();


            calcularTotales();

        }
    );



/* =========================================================
   EVENTOS
========================================================= */

function ligarEventos() {


    document
        .querySelectorAll(
            '.campo-debe, .campo-haber, select[name="cuenta_id[]"]'
        )
        .forEach(i => {


            i.removeEventListener(
                'input',
                calcularTotales
            );


            i.removeEventListener(
                'change',
                calcularTotales
            );

            i.addEventListener(
                'input',
                calcularTotales
            );

            i.addEventListener(
                'change',
                calcularTotales
            );


        });



    document
        .querySelectorAll(
            '.btn-quitar'
        )
        .forEach(b => {


            b.onclick =
                function() {


                    if (
                        tbody.rows.length > 1
                    ) {


                        this
                            .closest('tr')
                            .remove();


                        calcularTotales();


                    }

                };

        });

}



/* =========================================================
   VALIDAR FORMULARIO
========================================================= */

document
    .getElementById(
        'formAsiento'
    )
    .addEventListener(
        'submit',
        function(e) {


            let debe = 0;

            let haber = 0;



            document
                .querySelectorAll(
                    '.campo-debe'
                )
                .forEach(i => {


                    debe +=
                        parseFloat(
                            i.value || 0
                        );

                });



            document
                .querySelectorAll(
                    '.campo-haber'
                )
                .forEach(i => {


                    haber +=
                        parseFloat(
                            i.value || 0
                        );

                });



            let lineasValidas = 0;
            let hayLineaInvalida = false;

            document.querySelectorAll('#tablaLineas tbody tr').forEach(fila => {
                const cuenta = fila.querySelector('select[name="cuenta_id[]"]');
                const campoDebe = fila.querySelector('.campo-debe');
                const campoHaber = fila.querySelector('.campo-haber');
                const valorDebe = Math.round((parseFloat(campoDebe?.value || '0') || 0) * 100);
                const valorHaber = Math.round((parseFloat(campoHaber?.value || '0') || 0) * 100);
                const tieneCuenta = !!cuenta?.value;
                const tieneImporte = valorDebe > 0 || valorHaber > 0;

                if (tieneCuenta && tieneImporte && !(valorDebe > 0 && valorHaber > 0)) {
                    lineasValidas++;
                } else if (tieneCuenta || tieneImporte) {
                    hayLineaInvalida = true;
                }
            });

            if (
                hayLineaInvalida ||
                lineasValidas < 2 ||
                Math.round(debe * 100) !== Math.round(haber * 100) ||
                Math.round(debe * 100) <= 0
            ) {

                e.preventDefault();

                alert(hayLineaInvalida
                    ? 'No se puede guardar: cada línea debe tener una cuenta y solo Debe o Haber.'
                    : 'No se puede guardar: la partida no cumple la Partida Doble (Debe debe ser igual a Haber).');

            }

        }
    );



/* =========================================================
   INICIALIZAR
========================================================= */

ligarEventos();

calcularTotales();

});

</script>



<?php

require_once 'includes/footer.php';

?>