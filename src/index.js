import { __ } from '@wordpress/i18n';
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { decodeEntities } from '@wordpress/html-entities';
import { getSetting } from '@woocommerce/settings';
import { useEffect, useState, createPortal } from '@wordpress/element';
import { Spinner } from '@wordpress/components';
import upiqr from './upiqr.js'

const settings = getSetting( 'upi_data', {} )
const { options: { title, description, upi_id: payeeVPA, upi_name: payeeName, timeout } } = settings
const label = decodeEntities( title )

const Upi = {
	name: "upi",
	label: <Label />,
	content: <Content />,
	edit: <Content />,
	canMakePayment: () => payeeVPA && payeeName && settings.amount,
	ariaLabel: label,
	supports: { features: settings.features },
}
registerPaymentMethod( Upi )

function Label( props ) {
	const { PaymentMethodLabel } = props.components;
	return <div className="upi-label">
		<PaymentMethodLabel text={ label } />
		<img src={ settings.images.icon } />
	</div>
}

function Content( props ) {
	const { eventRegistration } = props;
	const { onPaymentSetup, onCheckoutSuccess, onCheckoutFail } = eventRegistration;
	const[ modal, setModal ] = useState( false )
	const[ upiData, setUpiData ] = useState( { payeeVPA, payeeName, amount: settings.amount } )
	const[ redirect, setRedirect ] = useState( '' )

	const onComplete = ( status = 'success' ) => {
		console.log( redirect )
		window.location = redirect
	}

	useEffect( () => {
		const unSubP = onPaymentSetup( async () => setModal( true ) )
		// Todo: Order creation failed. Show notice to retry
		const unSubF = onCheckoutFail( async () => setModal( false ) )

		const unSubS = onCheckoutSuccess( async ( d ) => {
			setUpiData( { ...upiData, transactionRef: `id-${ d.orderId }-time-${ getUpiTime() }` } )
			setRedirect( d.processingResponse.paymentDetails.redirect )
		} )

		// Unsubscribes when this component is unmounted.
		return () => { unSubP(); unSubS(); unSubF(); }
	}, [ onPaymentSetup, onCheckoutSuccess, onCheckoutFail ] )

	return <>
		{ decodeEntities( description || '' ) }
		{ modal && <QrModal upiData={ upiData } cb={ onComplete } /> }
	</>
}

function QrModal( props ) {
	const { payeeName, amount } = props.upiData
	const [ qr, setQr ] = useState( '' )
	const [ time, setTime ] = useState( parseInt( timeout ) )

	useEffect( () => {
		upiqr( props.upiData, { width: 400, margin: 0, color: { dark: '#213548' } } )
			.then( ( { qr } ) => setQr( qr ) )
			.catch( e => { } );
	}, [ props.upiData ] )

	useEffect( () => {
		if ( ! ( 'transactionRef' in props.upiData ) ) return
		if ( time < 1 ) {
			props.cb( 'timeout' )
			return
		}
		setTimeout(() => {
			setTime( time - 1 )
		}, 1000)
	}, [ time, props.upiData ] )

	return createPortal(
		<div className="upi-modal upi-center">
			<div className="upi-modal-bg"></div>
			<div className="upi-wrapper upi-center">
				<div><span>Paying: </span><b>{ payeeName }</b></div>
				<div className="upi-code-wrapper">
					<img src={ qr } alt="QR Code" />
					{ ( 'transactionRef' in props.upiData ) || <div className="upi-overlay upi-center">
						<div className="upi-overtext"><Spinner /><div>Generating QR Code</div></div>
					</div>}
				</div>
				<div>
					<div className="upi-guide">Make payment within <code>00:{String( time ).padStart( 2, '0' )}</code> using any UPI app</div>
					<img src={ settings.images.all } />
				</div>
				<b>Amount: ₹{ amount }</b>
			</div>
		</div>,
		document.body
	)
}

function getUpiTime() {
	const d = new Date()
	return [ d.getMonth() + 1, d.getDate(), d.getHours(), d.getMinutes(), d.getSeconds() ].map( i => String( i ).padStart( 2, '0' ) ).join( '-' )
}
