function Handle( { onClick, text, disabled } ) {
	return (
		<button tabIndex="-1" disabled={ disabled } className="horizontal-slider-handle" onClick={ onClick }>
			<span className="screen-reader-text">{ text }</span>
		</button>
	);
}

export default Handle;
